<?php

namespace WCInvoicePrinter\Integration;

use WCInvoicePrinter\Invoice\InvoiceFactory;
use WCInvoicePrinter\Settings\SettingsRepository;
use WCInvoicePrinter\Printing\ProviderException;
use WCInvoicePrinter\Automation\Scheduler;

/** Persist immutable intent before asynchronous delivery; never blindly replay. */
final class ExportService {
	public const HOOK = 'wcip_process_export';
	private SettingsRepository $settings;
	private string $table;
	public function __construct( SettingsRepository $settings ) { global $wpdb; $this->settings = $settings; $this->table = $wpdb->prefix . 'wcip_export_outbox'; }
	public function initialize(): void {
		if ( ! $this->settings->get( 'export_enabled' ) || ! function_exists( 'as_schedule_recurring_action' ) || ! \ActionScheduler::is_initialized() ) { return; }
		if ( ! as_has_scheduled_action( 'wcip_recover_exports', array(), Scheduler::GROUP ) ) { as_schedule_recurring_action( time() + 60, 600, 'wcip_recover_exports', array(), Scheduler::GROUP, true ); }
	}
	public function on_fulfillment( array $state, string $event ): void {
		if ( ! $this->settings->get( 'export_on_packed' ) || ! $this->settings->get( 'export_enabled' ) || 'packed' !== $state['stage'] ) { return; }
		$order = wc_get_order( (int) $state['order_id'] );
		if ( $order instanceof \WC_Order ) { $this->enqueue( $order, 'packed:' . $state['order_id'] . ':' . $state['revision'] ); }
	}

	public function adapter(): ExportAdapterInterface {
		$adapter = apply_filters( 'wcip_export_adapter', new GenericWebhookAdapter( $this->settings ), $this->settings );
		if ( ! $adapter instanceof ExportAdapterInterface ) { throw new \InvalidArgumentException( 'Invalid export adapter.' ); }
		return $adapter;
	}
	private function configuration_hash(): string {
		// Secret changes also freeze queued work; no credential is saved in the outbox.
		return hash( 'sha256', wp_json_encode( array( $this->settings->get( 'export_endpoint' ), $this->settings->get( 'export_allowed_host' ), ( new GenericWebhookAdapter( $this->settings ) )->secret(), $this->adapter()->id() ) ) );
	}
	public function payload( \WC_Order $order, string $package_id = '' ): array {
		$data = ( new InvoiceFactory( $this->settings ) )->from_order( $order );
		$items = array(); $total = 0; $complete = true;
		foreach ( $data->items as $item ) {
			$weight = $item['weight'];
			if ( null === $weight['line_kg'] ) { $complete = false; } else { $total += $weight['line_kg']; }
			$items[] = array( 'order_item_id' => $item['item_id'], 'product_id' => $item['product_id'], 'variation_id' => $item['variation_id'], 'sku' => $item['sku'], 'sku_missing' => '' === $item['sku'], 'name' => sanitize_text_field( $item['name'] ), 'variation' => sanitize_text_field( $item['variation'] ), 'quantity' => $item['quantity'], 'refunded_quantity' => $item['refunded_quantity'], 'shipped_quantity' => null, 'unit' => 'item', 'weight' => $weight );
		}
		$payload = array( 'schema_version' => '1.0', 'event' => 'order_export', 'order_id' => $order->get_id(), 'order_number' => $order->get_order_number(), 'package_id' => $package_id ?: null, 'currency' => $order->get_currency(), 'shipping_method' => $order->get_shipping_method(), 'items' => $items, 'net_item_weight_kg' => $complete ? round( $total, 6 ) : null, 'measured_gross_weight_kg' => null, 'tracking_number' => null );
		if ( $this->settings->get( 'export_include_recipient' ) ) { $payload['recipient'] = $data->customer['recipient']; }
		return apply_filters( 'wcip_export_payload', $payload, $order, $package_id );
	}
	public function enqueue( \WC_Order $order, string $event_key, string $package_id = '' ): array {
		global $wpdb;
		if ( ! $this->settings->get( 'export_enabled' ) || ! preg_match( '/^[A-Za-z0-9:_-]{1,100}$/D', $event_key ) ) { throw new \InvalidArgumentException( 'Export is disabled or the event key is invalid.' ); }
		$this->adapter()->validate_configuration();
		$key = hash( 'sha256', 'wcip-export-v1:' . $order->get_id() . ':' . $package_id . ':' . $event_key );
		$row = $this->by_key( $key );
		if ( ! $row ) {
			$payload = wp_json_encode( $this->payload( $order, $package_id ), JSON_UNESCAPED_UNICODE );
			if ( ! is_string( $payload ) || strlen( $payload ) > 262144 ) { throw new \InvalidArgumentException( 'Export payload exceeds the size limit.' ); }
			$now = current_time( 'mysql', true );
			$wpdb->insert( $this->table, array( 'order_id' => $order->get_id(), 'idempotency_key' => $key, 'payload' => $payload, 'configuration_hash' => $this->configuration_hash(), 'status' => 'queued', 'created_at' => $now, 'updated_at' => $now ) );
			$row = $this->by_key( $key );
			if ( ! $row ) { throw new \RuntimeException( 'Export intent could not be saved.' ); }
		}
		if ( 'queued' === $row['status'] ) { $this->schedule( (int) $row['id'] ); }
		return $row;
	}
	private function by_key( string $key ): ?array { global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE idempotency_key = %s", $key ), ARRAY_A ); return is_array( $row ) ? $row : null; }
	public function find( int $id ): ?array { global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A ); return is_array( $row ) ? $row : null; }
	private function schedule( int $id, int $delay = 0 ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! \ActionScheduler::is_initialized() ) { return; }
		$args = array( 'export_id' => $id );
		if ( ! as_get_scheduled_actions( array( 'hook' => self::HOOK, 'args' => $args, 'group' => Scheduler::GROUP, 'status' => 'pending', 'per_page' => 1 ), 'ids' ) ) {
			if ( $delay ) { as_schedule_single_action( time() + $delay, self::HOOK, $args, Scheduler::GROUP, false ); }
			else { as_enqueue_async_action( self::HOOK, $args, Scheduler::GROUP, false ); }
		}
	}
	public function process( int $export_id ): void {
		global $wpdb;
		if ( 1 !== $wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET status = 'processing', attempts = attempts + 1, updated_at = %s WHERE id = %d AND status = 'queued'", current_time( 'mysql', true ), $export_id ) ) ) { return; }
		$row = $this->find( $export_id ); $started = false;
		try {
			if ( ! $row || ! $this->settings->get( 'export_enabled' ) || ! hash_equals( $row['configuration_hash'], $this->configuration_hash() ) ) { throw new \RuntimeException( 'Export disabled or configuration changed.' ); }
			$order = wc_get_order( (int) $row['order_id'] );
			if ( ! $order instanceof \WC_Order || in_array( $order->get_status(), array( 'trash', 'checkout-draft', 'cancelled', 'refunded' ), true ) ) { throw new \RuntimeException( 'Order unavailable for export.' ); }
			$adapter = $this->adapter(); $adapter->validate_configuration(); $started = true;
			$receipt = $adapter->submit( $row['payload'], $row['idempotency_key'] );
			if ( 1 !== $wpdb->update( $this->table, array( 'status' => 'accepted', 'receipt' => $receipt, 'error_code' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $export_id, 'status' => 'processing' ) ) ) { throw new \RuntimeException( 'Acceptance could not be saved.' ); }
		} catch ( \Throwable $error ) {
			$ambiguous = $error instanceof ProviderException ? $error->ambiguous() : $started;
			$retry = $error instanceof ProviderException && $error->retryable() && ! $ambiguous && (int) ( $row['attempts'] ?? 3 ) < 3;
			$status = $retry ? 'queued' : ( $ambiguous ? 'unknown' : 'failed' );
			$code = $error instanceof ProviderException ? $error->error_code() : ( $started ? 'receipt_unknown' : 'configuration_or_order' );
			$wpdb->update( $this->table, array( 'status' => $status, 'error_code' => $code, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $export_id, 'status' => 'processing' ) );
			if ( $retry ) { $this->schedule( $export_id, 60 * (int) $row['attempts'] ); }
		}
	}
	public function recover(): void {
		global $wpdb;
		// Retain receipts/idempotency evidence while removing old terminal snapshots.
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET payload = '' WHERE status IN ('accepted','failed') AND payload <> '' AND updated_at < %s ORDER BY id ASC LIMIT 50", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET status = 'unknown', error_code = 'worker_interrupted' WHERE status = 'processing' AND updated_at < %s", gmdate( 'Y-m-d H:i:s', time() - 600 ) ) );
		$cursor = (int) get_option( 'wcip_export_cursor', 0 );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, attempts, error_code, updated_at FROM {$this->table} WHERE status = 'queued' AND id > %d ORDER BY id ASC LIMIT 50", $cursor ), ARRAY_A );
		foreach ( $rows as $row ) {
			$delay = 'http_429' === $row['error_code'] ? max( 0, strtotime( $row['updated_at'] . ' UTC' ) + 60 * (int) $row['attempts'] - time() ) : 0;
			$this->schedule( (int) $row['id'], $delay ); $cursor = (int) $row['id'];
		}
		update_option( 'wcip_export_cursor', count( $rows ) === 50 ? $cursor : 0, false );
	}
	public function recent(): array { global $wpdb; return $wpdb->get_results( "SELECT id, order_id, status, attempts, receipt, error_code, updated_at FROM {$this->table} ORDER BY id DESC LIMIT 20", ARRAY_A ) ?: array(); }
}
