<?php

namespace WCInvoicePrinter\Rest;

use WCInvoicePrinter\Automation\PaidOrderReconciler;
use WCInvoicePrinter\Infrastructure\DatabaseLease;
use WCInvoicePrinter\Integration\ExportService;
use WCInvoicePrinter\Invoice\DocumentCodeService;
use WCInvoicePrinter\Fulfillment\FulfillmentService;
use WCInvoicePrinter\PrintJob\PrintJobRepository;
use WCInvoicePrinter\PrintJob\PrintJobService;
use WCInvoicePrinter\Settings\SettingsRepository;

final class OperationsController {
	private SettingsRepository $settings;
	private PrintJobService $service;
	private PaidOrderReconciler $reconciler;
	public function __construct( SettingsRepository $settings, PrintJobService $service, PrintJobRepository $jobs ) {
		$this->settings = $settings; $this->service = $service;
		$this->reconciler = new PaidOrderReconciler( $settings, $service, $jobs );
	}
	public function register(): void {
		$schemas = array(
			'preview' => array( 'from' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ), 'to' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ), 'page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 2000, 'default' => 1 ) ),
			'enqueue' => array( 'order_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer', 'minimum' => 1 ), 'minItems' => 1, 'maxItems' => 50, 'required' => true ), 'preview_token' => array( 'type' => 'string', 'maxLength' => 50, 'required' => true ), 'confirm' => array( 'type' => 'boolean', 'required' => true ) ),
			'scan' => array( 'reference' => array( 'type' => 'string', 'maxLength' => 50, 'required' => true ) ),
			'fulfillment' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ), 'revision' => array( 'type' => 'integer', 'minimum' => 0, 'required' => true ), 'stage' => array( 'type' => 'string', 'enum' => FulfillmentService::stages(), 'required' => true ), 'event_key' => array( 'type' => 'string', 'maxLength' => 64, 'pattern' => '^[A-Za-z0-9_-]+$', 'required' => true ) ),
			'export' => array( 'order_id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ), 'event_key' => array( 'type' => 'string', 'maxLength' => 64, 'pattern' => '^[A-Za-z0-9_-]+$', 'required' => true ) ),
		);
		$routes = array(
			'/reconciliation/preview' => array( 'POST', 'preview', 'can_backfill' ),
			'/reconciliation/enqueue' => array( 'POST', 'enqueue', 'can_backfill' ),
			'/scan' => array( 'POST', 'scan', 'can_scan' ),
			'/fulfillment/(?P<id>\d+)' => array( 'POST', 'fulfillment', 'can_fulfill' ),
			'/exports' => array( 'POST', 'export', 'can_export' ),
			'/exports/history' => array( 'GET', 'history', 'can_export' ),
			'/exports/test' => array( 'POST', 'test_export', 'can_export' ),
			'/diagnostics' => array( 'GET', 'diagnostics', 'can_backfill' ),
		);
		foreach ( $routes as $route => $config ) {
			register_rest_route( 'wc-invoice-printer/v1', $route, array( 'methods' => $config[0], 'callback' => array( $this, $config[1] ), 'permission_callback' => array( $this, $config[2] ), 'args' => $schemas[ $config[1] ] ?? array() ) );
		}
	}
	public function can_backfill(): bool { return current_user_can( 'wcip_manage_settings' ) && current_user_can( 'wcip_print_invoices' ); }
	public function can_scan(): bool { return current_user_can( 'wcip_scan_orders' ); }
	public function can_fulfill(): bool { return current_user_can( 'wcip_manage_fulfillment' ); }
	public function can_export(): bool { return current_user_can( 'wcip_export_orders' ); }
	private function authorize_order( int $id, string $cap ): \WC_Order {
		$order = wc_get_order( $id );
		if ( ! current_user_can( $cap ) || ! $order instanceof \WC_Order || in_array( $order->get_status(), array( 'trash', 'checkout-draft' ), true ) || ! apply_filters( 'wcip_can_access_order', current_user_can( 'edit_shop_order', $id ), $order, $cap ) ) { throw new \RuntimeException( 'Order unavailable.', 403 ); }
		return $order;
	}
	private function throttle( string $operation ): void {
		// A per-user one-second slot prevents bursts and duplicate HID submissions.
		$lease = new DatabaseLease();
		if ( ! $lease->acquire( 'api_' . hash( 'sha1', get_current_user_id() . ':' . $operation . ':' . time() ), 2 ) ) { throw new \RuntimeException( 'Request rate exceeded.', 429 ); }
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wcip_runtime_leases WHERE expires_at < %d", time() - 300 ) );
	}
	private function guarded( callable $callback ) {
		try { return new \WP_REST_Response( $callback() ); }
		catch ( \Throwable $error ) {
			$status = $error instanceof \InvalidArgumentException ? 400 : ( in_array( $error->getCode(), array( 403, 404, 409, 429 ), true ) ? $error->getCode() : 500 );
			return new \WP_Error( 'wcip_operations_failed', __( 'The operation could not complete. Check permissions, input and configuration, then review diagnostics.', 'wc-invoice-printer' ), array( 'status' => $status ) );
		}
	}
	public function preview( \WP_REST_Request $request ) {
		return $this->guarded( function () use ( $request ): array {
			if ( ! $this->can_backfill() ) { throw new \RuntimeException( 'Permission denied.', 403 ); }
			$this->throttle( 'preview' );
			foreach ( array( 'from', 'to' ) as $key ) { if ( ! is_int( $request[ $key ] ) || $request[ $key ] < 1 ) { throw new \InvalidArgumentException( 'Invalid UTC timestamp.' ); } }
			$page = $request['page'] ?? 1;
			if ( ! is_int( $page ) ) { throw new \InvalidArgumentException( 'Invalid page.' ); }
			$result = $this->reconciler->preview( $request['from'], min( time(), $request['to'] ), $page );
			$ids = array_column( array_filter( $result['items'], static function ( array $row ): bool { return $row['eligible'] && ! $row['existing_job_id']; } ), 'order_id' );
			$token = wp_generate_uuid4();
			set_transient( 'wcip_preview_' . hash( 'sha256', $token ), array( 'user' => get_current_user_id(), 'ids' => $ids ), 900 );
			$result['preview_token'] = $token;
			return $result;
		} );
	}
	public function enqueue( \WP_REST_Request $request ) {
		return $this->guarded( function () use ( $request ): array {
			if ( ! $this->can_backfill() ) { throw new \RuntimeException( 'Permission denied.', 403 ); }
			$this->throttle( 'backfill' );
			$ids = $request['order_ids']; $token = $request['preview_token'];
			if ( true !== $request['confirm'] || ! is_string( $token ) || strlen( $token ) > 50 || ! is_array( $ids ) || ! $ids || count( $ids ) > 50 || ! $this->settings->get( 'automatic_enabled' ) ) { throw new \InvalidArgumentException( 'Preview and explicitly confirm a selection of up to 50 orders with automatic printing enabled.' ); }
			$preview = get_transient( 'wcip_preview_' . hash( 'sha256', $token ) );
			if ( ! is_array( $preview ) || $preview['user'] !== get_current_user_id() ) { throw new \RuntimeException( 'Preview expired.', 409 ); }
			$orders = array();
			foreach ( array_unique( $ids ) as $id ) {
				if ( ! is_int( $id ) || ! in_array( $id, $preview['ids'], true ) ) { throw new \InvalidArgumentException( 'Selection was not previewed.' ); }
				$orders[] = $this->authorize_order( $id, 'wcip_print_invoices' );
			}
			$jobs = array(); $blocked = array();
			foreach ( $orders as $order ) { $job = $this->service->create_automatic( $order ); if ( $job ) { $jobs[] = (int) $job['id']; } else { $blocked[] = $order->get_id(); } }
			delete_transient( 'wcip_preview_' . hash( 'sha256', $token ) );
			return array( 'job_ids' => $jobs, 'blocked_order_ids' => $blocked );
		} );
	}
	public function scan( \WP_REST_Request $request ) {
		return $this->guarded( function () use ( $request ): array {
			if ( ! $this->can_scan() ) { throw new \RuntimeException( 'Permission denied.', 403 ); }
			$this->throttle( 'scan' );
			if ( ! is_string( $request['reference'] ) || strlen( $request['reference'] ) > 50 ) { throw new \InvalidArgumentException( 'Invalid reference.' ); }
			$row = ( new DocumentCodeService() )->lookup( $request['reference'] );
			if ( ! $row ) { throw new \RuntimeException( 'Reference unavailable.', 404 ); }
			$order = $this->authorize_order( (int) $row['order_id'], 'wcip_scan_orders' );
			$exports = new ExportService( $this->settings ); $payload = $exports->payload( $order, $row['package_id'] );
			unset( $payload['recipient'] );
			$result = array( 'reference' => DocumentCodeService::normalize_reference( $request['reference'] ), 'document_type' => $row['document_type'], 'data' => $payload, 'fulfillment' => ( new FulfillmentService( $this->settings ) )->get( $order->get_id() ) );
			if ( $this->settings->get( 'export_on_scan' ) && $this->can_export() ) { $this->authorize_order( $order->get_id(), 'wcip_export_orders' ); $result['export'] = $this->public_export( $exports->enqueue( $order, 'scan:' . $row['reference'], $row['package_id'] ) ); }
			return $result;
		} );
	}
	public function fulfillment( \WP_REST_Request $request ) {
		return $this->guarded( function () use ( $request ): array {
			$this->throttle( 'fulfillment' );
			if ( ! ( is_int( $request['id'] ) || ( is_string( $request['id'] ) && preg_match( '/^[1-9][0-9]*$/D', $request['id'] ) ) ) || ! is_int( $request['revision'] ) || $request['revision'] < 0 || ! is_string( $request['stage'] ) || ! is_string( $request['event_key'] ) || strlen( $request['event_key'] ) > 64 ) { throw new \InvalidArgumentException( 'Invalid transition.' ); }
			$order = $this->authorize_order( (int) $request['id'], 'wcip_manage_fulfillment' );
			if ( ! ( new \WCInvoicePrinter\Automation\PaymentEligibilityPolicy( $this->settings ) )->fulfillable( $order ) ) { throw new \RuntimeException( 'Order not fulfillable.', 409 ); }
			return ( new FulfillmentService( $this->settings ) )->transition( $order->get_id(), $request['stage'], 'manual:' . $order->get_id() . ':' . $request['event_key'], $request['revision'], get_current_user_id() );
		} );
	}
	public function export( \WP_REST_Request $request ) {
		return $this->guarded( function () use ( $request ): array {
			$this->throttle( 'export' );
			if ( ! is_int( $request['order_id'] ) || ! is_string( $request['event_key'] ) || ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/D', $request['event_key'] ) ) { throw new \InvalidArgumentException( 'Invalid export identity.' ); }
			$order = $this->authorize_order( $request['order_id'], 'wcip_export_orders' );
			return $this->public_export( ( new ExportService( $this->settings ) )->enqueue( $order, 'manual:' . $request['event_key'] ) );
		} );
	}
	private function public_export( array $row ): array { return array_intersect_key( $row, array_flip( array( 'id', 'status', 'attempts', 'receipt', 'error_code' ) ) ); }
	public function history() { return $this->guarded( function (): array { if ( ! $this->can_export() ) { throw new \RuntimeException( 'Denied.', 403 ); } return array( 'items' => ( new ExportService( $this->settings ) )->recent() ); } ); }
	public function test_export() { return $this->guarded( function (): array { if ( ! $this->can_export() || ! current_user_can( 'wcip_manage_settings' ) ) { throw new \RuntimeException( 'Denied.', 403 ); } $this->throttle( 'export_test' ); return ( new ExportService( $this->settings ) )->adapter()->test_connection(); } ); }
	public function diagnostics() {
		return $this->guarded( function (): array {
			if ( ! $this->can_backfill() ) { throw new \RuntimeException( 'Denied.', 403 ); }
			return array( 'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON, 'scheduler_initialized' => class_exists( 'ActionScheduler' ) && \ActionScheduler::is_initialized(), 'automatic_enabled' => $this->settings->get( 'automatic_enabled' ), 'printer_configured' => '' !== $this->settings->cups_endpoint() && '' !== $this->settings->get( 'cups_printer_id' ), 'discovery_since' => get_option( 'wcip_discovery_since' ), 'cursor' => get_option( 'wcip_reconciliation_state', array() ), 'last_run' => get_option( 'wcip_reconciliation_stats', array() ) );
		} );
	}
}
