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
	public function __construct(
		private readonly PrintJobRepository $jobs,
		private readonly InvoiceFactory $invoices,
		private readonly HtmlRenderer $html,
		private readonly PdfRendererInterface $pdf,
		private readonly TemplateRegistry $templates,
		private readonly PrintProviderRegistry $providers,
		private readonly RetryPolicy $retry,
		private readonly Scheduler $scheduler
	) {}

	public function process( int $job_id ): void {
		if ( ! $this->jobs->claim( $job_id ) ) {
			return;
		}
		$job = $this->jobs->find( $job_id );
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
			do_action( 'wcip_before_print_submission', $job_id, $job['provider_id'] );
			$result = $this->providers->get( $job['provider_id'] )->submit( $pdf, $job['printer_id'], (int) $job['copies'], sprintf( 'Invoice %s', $order->get_order_number() ) );
			$this->jobs->submitted( $job_id, $result->external_job_id );
			do_action( 'wcip_after_print_submission', $job_id, $result->external_job_id );
		} catch ( ProviderException $exception ) {
			$attempt = (int) ( $job['attempt_count'] ?? 1 );
			if ( $this->retry->should_retry( $exception, $attempt ) ) {
				$this->jobs->requeue( $job_id, $exception->error_code(), $exception->getMessage() );
				$this->scheduler->enqueue( $job_id, $this->retry->delay( $attempt ) );
				return;
			}
			$status = $exception->ambiguous() ? JobStatus::UNKNOWN : JobStatus::FAILED;
			$this->jobs->fail( $job_id, $status, $exception->error_code(), $exception->getMessage() );
			do_action( 'wcip_print_failure', $job_id, $exception->error_code(), $status->value );
		} catch ( \Throwable $exception ) {
			$this->jobs->fail( $job_id, JobStatus::FAILED, 'generation_failed', $exception->getMessage() );
			do_action( 'wcip_print_failure', $job_id, 'generation_failed', JobStatus::FAILED->value );
		}
	}
}
