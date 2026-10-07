<?php

namespace WCInvoicePrinter\Rest;

use WCInvoicePrinter\Automation\Scheduler;
use WCInvoicePrinter\Invoice\InvoiceFactory;
use WCInvoicePrinter\Pdf\PdfRendererInterface;
use WCInvoicePrinter\Printing\PrintProviderRegistry;
use WCInvoicePrinter\Printing\ProviderException;
use WCInvoicePrinter\PrintJob\PrintJobRepository;
use WCInvoicePrinter\PrintJob\PrintJobService;
use WCInvoicePrinter\Settings\SettingsRepository;
use WCInvoicePrinter\Template\HtmlRenderer;
use WCInvoicePrinter\Template\TemplateRegistry;

final class RestController {
	private const NS = 'wc-invoice-printer/v1';

	private SettingsRepository $settings;
	private PrintProviderRegistry $providers;
	private PrintJobService $job_service;
	private PrintJobRepository $jobs;
	private Scheduler $scheduler;
	private InvoiceFactory $invoices;
	private HtmlRenderer $html;
	private PdfRendererInterface $pdf;
	private TemplateRegistry $templates;

	public function __construct(
		SettingsRepository $settings,
		PrintProviderRegistry $providers,
		PrintJobService $job_service,
		PrintJobRepository $jobs,
		Scheduler $scheduler,
		InvoiceFactory $invoices,
		HtmlRenderer $html,
		PdfRendererInterface $pdf,
		TemplateRegistry $templates
	) {
		$this->settings = $settings;
		$this->providers = $providers;
		$this->job_service = $job_service;
		$this->jobs = $jobs;
		$this->scheduler = $scheduler;
		$this->invoices = $invoices;
		$this->html = $html;
		$this->pdf = $pdf;
		$this->templates = $templates;
	}

	public function register(): void {
		register_rest_route( self::NS, '/connection/test', array( 'methods' => 'POST', 'callback' => array( $this, 'test_connection' ), 'permission_callback' => array( $this, 'can_manage' ) ) );
		register_rest_route( self::NS, '/printers', array( 'methods' => 'GET', 'callback' => array( $this, 'printers' ), 'permission_callback' => array( $this, 'can_manage' ), 'args' => array( 'refresh' => array( 'type' => 'boolean', 'default' => false ) ) ) );
		register_rest_route( self::NS, '/test-print', array( 'methods' => 'POST', 'callback' => array( $this, 'test_print' ), 'permission_callback' => array( $this, 'can_manage' ), 'args' => array( 'printer_id' => array( 'type' => 'string', 'required' => true, 'pattern' => '^[1-9][0-9]*$' ) ) ) );
		register_rest_route( self::NS, '/print', array( 'methods' => 'POST', 'callback' => array( $this, 'manual_print' ), 'permission_callback' => array( $this, 'can_print' ), 'args' => array( 'order_ids' => array( 'type' => 'array', 'required' => true, 'items' => array( 'type' => 'integer', 'minimum' => 1 ), 'minItems' => 1, 'maxItems' => 50 ), 'template_id' => array( 'type' => 'string', 'required' => true, 'pattern' => '^[a-z0-9_-]+$' ), 'provider_id' => array( 'type' => 'string', 'required' => true, 'enum' => array( 'printnode' ) ), 'printer_id' => array( 'type' => 'string', 'required' => true, 'pattern' => '^[1-9][0-9]*$' ), 'copies' => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1, 'maximum' => 20 ) ) ) );
		register_rest_route( self::NS, '/jobs/(?P<id>\\d+)/cancel', array( 'methods' => 'POST', 'callback' => array( $this, 'cancel_job' ), 'permission_callback' => array( $this, 'can_manage_jobs' ), 'args' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ) ) );
		register_rest_route( self::NS, '/jobs/(?P<id>\\d+)/retry', array( 'methods' => 'POST', 'callback' => array( $this, 'retry_job' ), 'permission_callback' => array( $this, 'can_manage_jobs' ), 'args' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ) ) );
	}

	public function can_manage(): bool { return current_user_can( 'wcip_manage_settings' ); }
	public function can_print(): bool { return current_user_can( 'wcip_print_invoices' ); }
	public function can_view_jobs(): bool { return current_user_can( 'wcip_view_print_jobs' ); }
	public function can_manage_jobs(): bool { return $this->can_view_jobs() && $this->can_print(); }

	/** @return \WP_REST_Response|\WP_Error */
	public function test_connection() {
		return $this->provider_call( fn() => $this->providers->get( 'printnode' )->test_connection() );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function printers( \WP_REST_Request $request ) {
		return $this->provider_call( fn() => array( 'printers' => $this->providers->get( 'printnode' )->printers( (bool) $request['refresh'] ) ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function test_print( \WP_REST_Request $request ) {
		if ( ! is_string( $request['printer_id'] ) || ! preg_match( '/^[1-9][0-9]*$/D', $request['printer_id'] ) ) {
			return new \WP_Error( 'wcip_invalid_print_request', __( 'Choose a valid printer.', 'wc-invoice-printer' ), array( 'status' => 400 ) );
		}
		try {
			$template = $this->templates->get( (string) $this->settings->get( 'default_template', 'classic' ) );
			$html     = $this->html->render( $this->invoices->sample( is_rtl() ), $template->id );
			$result   = $this->providers->get( 'printnode' )->submit( $this->pdf->render( $html, $template ), (string) $request['printer_id'], 1, __( 'Invoice printer test page', 'wc-invoice-printer' ) );
			return new \WP_REST_Response( array( 'submitted' => true, 'external_job_id' => $result->external_job_id ), 201 );
		} catch ( \Throwable $error ) {
			return $this->error_response( $error );
		}
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function manual_print( \WP_REST_Request $request ) {
		if ( ! is_array( $request['order_ids'] ) || ! $request['order_ids'] || count( $request['order_ids'] ) > 50 ) {
			return new \WP_Error( 'wcip_invalid_print_request', __( 'Select between 1 and 50 orders.', 'wc-invoice-printer' ), array( 'status' => 400 ) );
		}
		$template_id = $request['template_id'];
		$printer_id  = $request['printer_id'];
		$copies      = $request['copies'] ?? 1;
		if ( ! is_string( $template_id ) || ! $this->templates->has( $template_id ) || 'printnode' !== $request['provider_id'] || ! is_string( $printer_id ) || ! preg_match( '/^[1-9][0-9]*$/D', $printer_id ) || ! is_numeric( $copies ) || (float) $copies !== (float) (int) $copies || (int) $copies < 1 || (int) $copies > 20 ) {
			return new \WP_Error( 'wcip_invalid_print_request', __( 'Choose a valid template, printer, destination, and number of copies.', 'wc-invoice-printer' ), array( 'status' => 400 ) );
		}
		if ( '' === $this->settings->api_key() ) {
			return new \WP_Error( 'wcip_not_configured', __( 'Configure the PrintNode credential before printing.', 'wc-invoice-printer' ), array( 'status' => 400 ) );
		}
		// Validate the whole selection before creating or scheduling any job.
		$orders = array();
		foreach ( $request['order_ids'] as $order_id ) {
			if ( ! is_numeric( $order_id ) || (float) $order_id !== (float) (int) $order_id || (int) $order_id < 1 ) {
				return new \WP_Error( 'wcip_invalid_order', __( 'One of the selected order IDs is invalid.', 'wc-invoice-printer' ), array( 'status' => 400 ) );
			}
			$order = wc_get_order( (int) $order_id );
			if ( ! $order instanceof \WC_Order || in_array( $order->get_status(), array( 'trash', 'checkout-draft' ), true ) ) {
				return new \WP_Error( 'wcip_invalid_order', __( 'One of the selected orders does not exist or cannot be printed.', 'wc-invoice-printer' ), array( 'status' => 404 ) );
			}
			$orders[ (int) $order_id ] = $order;
		}
		$created = array();
		foreach ( $orders as $order ) {
			try {
				$job       = $this->job_service->create_manual( $order, $template_id, 'printnode', $printer_id, (int) $copies );
				$created[] = (int) $job['id'];
				if ( ! in_array( $job['status'], array( 'queued', 'processing', 'submitted' ), true ) ) {
					return new \WP_Error( 'wcip_queue_failed', __( 'A print job could not be queued. Review Print Jobs before trying again.', 'wc-invoice-printer' ), array( 'status' => 503, 'job_ids' => $created ) );
				}
			} catch ( \InvalidArgumentException $error ) {
				return new \WP_Error( 'wcip_invalid_print_request', $error->getMessage(), array( 'status' => 400, 'job_ids' => $created ) );
			} catch ( \Throwable $error ) {
				return new \WP_Error( 'wcip_print_failed', __( 'The print request stopped. Review Print Jobs before trying again.', 'wc-invoice-printer' ), array( 'status' => 500, 'job_ids' => $created ) );
			}
		}
		return new \WP_REST_Response( array( 'queued' => count( $created ), 'job_ids' => $created ), 201 );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function cancel_job( \WP_REST_Request $request ) {
		$job = $this->jobs->find( (int) $request['id'] );
		if ( ! $job ) {
			return new \WP_Error( 'wcip_job_not_found', __( 'Print job not found.', 'wc-invoice-printer' ), array( 'status' => 404 ) );
		}
		if ( ! $this->jobs->cancel( (int) $job['id'] ) ) {
			return new \WP_Error( 'wcip_job_not_cancellable', __( 'Only queued jobs can be cancelled.', 'wc-invoice-printer' ), array( 'status' => 409 ) );
		}
		return new \WP_REST_Response( array( 'cancelled' => true ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function retry_job( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! $this->jobs->find( $id ) ) {
			return new \WP_Error( 'wcip_job_not_found', __( 'Print job not found.', 'wc-invoice-printer' ), array( 'status' => 404 ) );
		}
		if ( ! $this->jobs->retry_failed( $id ) ) {
			return new \WP_Error( 'wcip_unsafe_retry', __( 'This job is not eligible for a safe retry. Reprint intentionally instead.', 'wc-invoice-printer' ), array( 'status' => 409 ) );
		}
		if ( ! $this->scheduler->enqueue( $id ) ) {
			return new \WP_Error( 'wcip_queue_failed', __( 'The print job could not be queued. Review Print Jobs before trying again.', 'wc-invoice-printer' ), array( 'status' => 503 ) );
		}
		return new \WP_REST_Response( array( 'queued' => true ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	private function provider_call( callable $callable ) {
		try {
			return new \WP_REST_Response( $callable() );
		} catch ( \Throwable $error ) {
			return $this->error_response( $error );
		}
	}

	private function error_response( \Throwable $error ): \WP_Error {
		$code = $error instanceof ProviderException ? $error->error_code() : 'operation_failed';
		$message = $error instanceof ProviderException ? sanitize_text_field( $error->getMessage() ) : __( 'The print operation failed. Check the configuration and try again.', 'wc-invoice-printer' );
		return new \WP_Error( 'wcip_' . sanitize_key( $code ), $message, array( 'status' => 502 ) );
	}
}
