<?php

namespace WCInvoicePrinter\Fulfillment;

use WCInvoicePrinter\Automation\PaymentEligibilityPolicy;
use WCInvoicePrinter\Settings\SettingsRepository;

/** Warehouse state in plugin tables; no native payment, stock or order writes. */
final class FulfillmentService {
	private SettingsRepository $settings;
	public function __construct( SettingsRepository $settings ) { $this->settings = $settings; }
	public static function stages(): array { return array( 'not_started', 'preparing', 'packed', 'ready_to_ship', 'shipped', 'delivered' ); }
	public static function labels(): array { return array( 'not_started' => __( 'Not started', 'wc-invoice-printer' ), 'preparing' => __( 'Preparing', 'wc-invoice-printer' ), 'packed' => __( 'Packed', 'wc-invoice-printer' ), 'ready_to_ship' => __( 'Ready to ship', 'wc-invoice-printer' ), 'shipped' => __( 'Shipped', 'wc-invoice-printer' ), 'delivered' => __( 'Delivered', 'wc-invoice-printer' ) ); }

	public function get( int $order_id ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wcip_fulfillment WHERE order_id = %d", $order_id ), ARRAY_A );
		return is_array( $row ) ? $row : array( 'order_id' => $order_id, 'stage' => 'not_started', 'revision' => 0 );
	}

	public function on_confirmation( array $job ): void {
		if ( ! $this->settings->get( 'fulfillment_on_confirmation' ) || 'invoice' !== ( $job['document_type'] ?? 'invoice' ) ) { return; }
		$order = wc_get_order( (int) $job['order_id'] );
		if ( ! $order instanceof \WC_Order || ! ( new PaymentEligibilityPolicy( $this->settings ) )->fulfillable( $order ) ) { return; }
		$this->transition( $order->get_id(), (string) $this->settings->get( 'fulfillment_confirmation_stage' ), 'confirmation:' . $job['id'], 0, 0 );
	}

	public function transition( int $order_id, string $stage, string $event, int $revision, int $actor ): array {
		global $wpdb;
		if ( ! in_array( $stage, self::stages(), true ) || ! preg_match( '/^[a-zA-Z0-9:_-]{1,100}$/D', $event ) ) { throw new \InvalidArgumentException( 'Invalid fulfillment event.' ); }
		$table = $wpdb->prefix . 'wcip_fulfillment';
		$events = $wpdb->prefix . 'wcip_fulfillment_events';
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { throw new \RuntimeException( 'Could not start fulfillment transaction.' ); }
		try {
			$now = current_time( 'mysql', true );
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (order_id, stage, revision, updated_at, updated_by, last_event) VALUES (%d, 'not_started', 0, %s, 0, '')", $order_id, $now ) );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d FOR UPDATE", $order_id ), ARRAY_A );
			if ( ! $row || ! empty( $wpdb->last_error ) ) { throw new \RuntimeException( 'Could not lock fulfillment state.' ); }
			$previous = $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$events} WHERE event_key = %s", $event ) );
			if ( $previous ) {
				if ( (int) $previous !== $order_id ) { throw new \InvalidArgumentException( 'Event belongs to another order.' ); }
				$wpdb->query( 'COMMIT' ); return $row;
			}
			if ( (int) $row['revision'] !== $revision ) {
				if ( 0 === strpos( $event, 'confirmation:' ) ) { $wpdb->query( 'COMMIT' ); return $row; }
				throw new \RuntimeException( 'Fulfillment changed. Refresh before applying an override.', 409 );
			}
			if ( false === $wpdb->insert( $events, array( 'event_key' => $event, 'order_id' => $order_id, 'from_stage' => $row['stage'], 'to_stage' => $stage, 'created_at' => $now, 'actor_id' => $actor ) ) || false === $wpdb->update( $table, array( 'stage' => $stage, 'revision' => $revision + 1, 'updated_at' => $now, 'updated_by' => $actor, 'last_event' => $event ), array( 'order_id' => $order_id, 'revision' => $revision ) ) ) { throw new \RuntimeException( 'Fulfillment could not be saved.' ); }
			if ( false === $wpdb->query( 'COMMIT' ) ) { throw new \RuntimeException( 'Fulfillment could not commit.' ); }
			$result = $this->get( $order_id );
			try { do_action( 'wcip_fulfillment_changed', $result, $event ); } catch ( \Throwable $observer ) { /* The warehouse fact is already committed. */ }
			return $result;
		} catch ( \Throwable $error ) { $wpdb->query( 'ROLLBACK' ); throw $error; }
	}
}
