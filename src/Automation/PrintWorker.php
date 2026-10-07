<?php

namespace WCInvoicePrinter\Automation;

use WCInvoicePrinter\Invoice\InvoiceFactory;
use WCInvoicePrinter\Pdf\PdfRendererInterface;
use WCInvoicePrinter\Printing\PrintProviderRegistry;
use WCInvoicePrinter\Printing\ProviderException;
use WCInvoicePrinter\Printing\RetryPolicy;
use WCInvoicePrinter\PrintJob\JobStatus;
use WCInvoicePrinter\PrintJob\PrintJobRepository;
use WCInvoicePrinter\Template\HtmlRenderer;
use WCInvoicePrinter\Template\TemplateRegistry;

final class PrintWorker {
	private PrintJobRepository $jobs;
	private InvoiceFactory $invoices;
	private HtmlRenderer $html;
	private PdfRendererInterface $pdf;
	private TemplateRegistry $templates;
	private PrintProviderRegistry $providers;
	private RetryPolicy $retry;
	private Scheduler $scheduler;

	public function __construct(
		PrintJobRepository $jobs,
		InvoiceFactory $invoices,
		HtmlRenderer $html,
		PdfRendererInterface $pdf,
		TemplateRegistry $templates,
		PrintProviderRegistry $providers,
		RetryPolicy $retry,
		Scheduler $scheduler
	) {
		$this->jobs = $jobs;
		$this->invoices = $invoices;
		$this->html = $html;
		$this->pdf = $pdf;
		$this->templates = $templates;
		$this->providers = $providers;
		$this->retry = $retry;
		$this->scheduler = $scheduler;
	}

	public function process( int $job_id ): void {
		if ( ! $this->jobs->claim( $job_id ) ) {
			return;
		}
		$job = $this->jobs->find( $job_id );
		if ( ! $job ) {
			return;
		}
		$submission_started = false;
		try {
			$order = wc_get_order( (int) $job['order_id'] );
			if ( ! $order instanceof \WC_Order ) {
				throw new \RuntimeException( __( 'The order no longer exists.', 'wc-invoice-printer' ) );
			}
			if ( 'automatic' === $job['trigger_type'] && ! $order->is_paid() ) {
				throw new \RuntimeException( __( 'The order is no longer paid, so automatic printing was stopped.', 'wc-invoice-printer' ) );
			}
			$template = $this->templates->get( $job['template_id'] );
			$html     = $this->html->render( $this->invoices->from_order( $order ), $template->id );
			$pdf      = $this->pdf->render( $html, $template );
			$provider = $this->providers->get( $job['provider_id'] );
			do_action( 'wcip_before_print_submission', $job_id, $job['provider_id'] );
			if ( 'automatic' === $job['trigger_type'] ) {
				// Rendering may take long enough for a refund/cancellation to arrive.
				// Refresh immediately before dispatch rather than using the snapshot
				// that was loaded before generating the invoice.
				$current_order = wc_get_order( (int) $job['order_id'] );
				if ( ! $current_order instanceof \WC_Order || ! $current_order->is_paid() ) {
					throw new \RuntimeException( __( 'The order is no longer paid, so automatic printing was stopped.', 'wc-invoice-printer' ) );
				}
			}
			$submission_started = true;
			$result = $provider->submit( $pdf, $job['printer_id'], (int) $job['copies'], sprintf( 'Invoice %s', $order->get_order_number() ) );
			if ( ! $this->jobs->submitted( $job_id, $result->external_job_id ) ) {
				throw new \RuntimeException( __( 'The provider accepted the print job, but its result could not be saved. Check PrintNode before reprinting.', 'wc-invoice-printer' ) );
			}
		} catch ( ProviderException $exception ) {
			$attempt = (int) ( $job['attempt_count'] ?? 1 );
			if ( $this->retry->should_retry( $exception, $attempt ) ) {
				if ( $this->jobs->requeue( $job_id, $exception->error_code(), $exception->getMessage() ) ) {
					$this->scheduler->enqueue( $job_id, $this->retry->delay( $attempt ) );
				}
				return;
			}
			$status = $exception->ambiguous() ? JobStatus::UNKNOWN : JobStatus::FAILED;
			$this->jobs->fail( $job_id, $status, $exception->error_code(), $exception->getMessage() );
			do_action( 'wcip_print_failure', $job_id, $exception->error_code(), $status );
			return;
		} catch ( \Throwable $exception ) {
			$status = $submission_started ? JobStatus::UNKNOWN : JobStatus::FAILED;
			$code   = $submission_started ? 'submission_unknown' : 'generation_failed';
			$this->jobs->fail( $job_id, $status, $code, $exception->getMessage() );
			do_action( 'wcip_print_failure', $job_id, $code, $status );
			return;
		}
		// Observer failures after acceptance must never turn a successful print
		// into a failed/retryable job. Action Scheduler can log the observer error.
		do_action( 'wcip_after_print_submission', $job_id, $result->external_job_id );
	}

	public function interrupted( int $action_id ): void {
		$job = $this->jobs->find_by_action( $action_id );
		if ( $job && $this->jobs->fail( (int) $job['id'], JobStatus::UNKNOWN, 'worker_interrupted', __( 'The worker stopped before recording a result. Check PrintNode before reprinting.', 'wc-invoice-printer' ) ) ) {
			do_action( 'wcip_print_failure', (int) $job['id'], 'worker_interrupted', JobStatus::UNKNOWN );
		}
	}
}
