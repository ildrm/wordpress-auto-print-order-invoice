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

	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly PrintProviderRegistry $providers,
		private readonly PrintJobService $job_service,
		private readonly PrintJobRepository $jobs,
		private readonly Scheduler $scheduler,
		private readonly InvoiceFactory $invoices,
		private readonly HtmlRenderer $html,
		private readonly PdfRendererInterface $pdf,
		private readonly TemplateRegistry $templates
	) {}

	public function register(): void {
		register_rest_route( self::NS, '/connection/test', array( 'methods' => 'POST', 'callback' => array( $this, 'test_connection' ), 'permission_callback' => array( $this, 'can_manage' ) ) );
		register_rest_route( self::NS, '/printers', array( 'methods' => 'GET', 'callback' => array( $this, 'printers' ), 'permission_callback' => array( $this, 'can_manage' ), 'args' => array( 'refresh' => array( 'type' => 'boolean', 'default' => false ) ) ) );
		register_rest_route( self::NS, '/test-print', array( 'methods' => 'POST', 'callback' => array( $this, 'test_print' ), 'permission_callback' => array( $this, 'can_manage' ), 'args' => array( 'printer_id' => array( 'type' => 'string', 'required' => true, 'pattern' => '^\\d+$' ) ) ) );
		register_rest_route( self::NS, '/print', array( 'methods' => 'POST', 'callback' => array( $this, 'manual_print' ), 'permission_callback' => array( $this, 'can_print' ), 'args' => array( 'order_ids' => array( 'type' => 'array', 'required' => true, 'items' => array( 'type' => 'integer', 'minimum' => 1 ), 'minItems' => 1, 'maxItems' => 50 ), 'template_id' => array( 'type' => 'string', 'required' => true, 'pattern' => '^[a-z0-9_-]+$' ), 'provider_id' => array( 'type' => 'string', 'required' => true, 'enum' => array( 'printnode' ) ), 'printer_id' => array( 'type' => 'string', 'required' => true, 'pattern' => '^\\d+$' ), 'copies' => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1, 'maximum' => 20 ) ) ) );
		register_rest_route( self::NS, '/jobs/(?P<id>\\d+)/cancel', array( 'methods' => 'POST', 'callback' => array( $this, 'cancel_job' ), 'permission_callback' => array( $this, 'can_view_jobs' ), 'args' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ) ) );
		register_rest_route( self::NS, '/jobs/(?P<id>\\d+)/retry', array( 'methods' => 'POST', 'callback' => array( $this, 'retry_job' ), 'permission_callback' => array( $this, 'can_view_jobs' ), 'args' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ) ) );
	}

	public function can_manage(): bool { return current_user_can( 'wcip_manage_settings' ); }
	public function can_print(): bool { return current_user_can( 'wcip_print_invoices' ); }
	public function can_view_jobs(): bool { return current_user_can( 'wcip_view_print_jobs' ); }

	public function test_connection(): \WP_REST_Response|\WP_Error {
		return $this->provider_call( fn() => $this->providers->get( 'printnode' )->test_connection() );
	}

	public function printers( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->provider_call( fn() => array( 'printers' => $this->providers->get( 'printnode' )->printers( (bool) $request['refresh'] ) ) );
	}

	public function test_print( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$template = $this->templates->get( (string) $this->settings->get( 'default_template', 'classic' ) );
			$html     = $this->html->render( $this->invoices->sample( is_rtl() ), $template->id );
			$result   = $this->providers->get( 'printnode' )->submit( $this->pdf->render( $html, $template ), (string) $request['printer_id'], 1, __( 'Invoice printer test page', 'wc-invoice-printer' ) );
			return new \WP_REST_Response( array( 'submitted' => true, 'external_job_id' => $result->external_job_id ), 201 );
		} catch ( \Throwable $error ) {
			return $this->error_response( $error );
		}
	}

	public function manual_print( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$created = array();
		foreach ( array_unique( array_map( 'absint', $request['order_ids'] ) ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				return new \WP_Error( 'wcip_invalid_order', __( 'One of the selected orders does not exist.', 'wc-invoice-printer' ), array( 'status' => 404 ) );
			}
			try {
				$job       = $this->job_service->create_manual( $order, (string) $request['template_id'], 'printnode', (string) $request['printer_id'], (int) $request['copies'] );
				$created[] = (int) $job['id'];
			} catch ( \InvalidArgumentException $error ) {
				return new \WP_Error( 'wcip_invalid_print_request', $error->getMessage(), array( 'status' => 400 ) );
			}
		}
		return new \WP_REST_Response( array( 'queued' => count( $created ), 'job_ids' => $created ), 201 );
	}

	public function cancel_job( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$job = $this->jobs->find( (int) $request['id'] );
		if ( ! $job ) {
			return new \WP_Error( 'wcip_job_not_found', __( 'Print job not found.', 'wc-invoice-printer' ), array( 'status' => 404 ) );
		}
		if ( ! $this->jobs->cancel( (int) $job['id'] ) ) {
			return new \WP_Error( 'wcip_job_not_cancellable', __( 'Only queued jobs can be cancelled.', 'wc-invoice-printer' ), array( 'status' => 409 ) );
		}
		return new \WP_REST_Response( array( 'cancelled' => true ) );
	}

	public function retry_job( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (int) $request['id'];
		if ( ! $this->jobs->retry_failed( $id ) ) {
			return new \WP_Error( 'wcip_unsafe_retry', __( 'This job is not eligible for a safe retry. Reprint intentionally instead.', 'wc-invoice-printer' ), array( 'status' => 409 ) );
		}
		$this->scheduler->enqueue( $id );
		return new \WP_REST_Response( array( 'queued' => true ) );
	}

	private function provider_call( callable $callable ): \WP_REST_Response|\WP_Error {
		try {
			return new \WP_REST_Response( $callable() );
		} catch ( \Throwable $error ) {
			return $this->error_response( $error );
		}
	}

	private function error_response( \Throwable $error ): \WP_Error {
		$code = $error instanceof ProviderException ? $error->error_code() : 'operation_failed';
		return new \WP_Error( 'wcip_' . sanitize_key( $code ), sanitize_text_field( $error->getMessage() ), array( 'status' => 502 ) );
	}
}
