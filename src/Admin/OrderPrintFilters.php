<?php

namespace WCInvoicePrinter\Admin;

/** Extend native queries with plugin history predicates; no standalone order SQL. */
final class OrderPrintFilters {
	private function state(): string {
		$value = isset( $_GET['wcip_print_state'] ) && is_string( $_GET['wcip_print_state'] ) ? sanitize_key( wp_unslash( $_GET['wcip_print_state'] ) ) : '';
		return in_array( $value, array( 'printed', 'not_printed', 'queued', 'processing', 'submitted', 'failed', 'unknown' ), true ) ? $value : '';
	}
	public function control( $type = 'shop_order', string $which = 'top' ): void {
		if ( 'shop_order' !== $type || 'top' !== $which || ! current_user_can( 'wcip_view_print_jobs' ) ) { return; }
		$labels = array( '' => __( 'All invoice print states', 'wc-invoice-printer' ), 'not_printed' => __( 'Eligible without an invoice job', 'wc-invoice-printer' ), 'printed' => __( 'Printed', 'wc-invoice-printer' ), 'queued' => __( 'Queued', 'wc-invoice-printer' ), 'processing' => __( 'Printing', 'wc-invoice-printer' ), 'submitted' => __( 'Awaiting confirmation', 'wc-invoice-printer' ), 'failed' => __( 'Print failed', 'wc-invoice-printer' ), 'unknown' => __( 'Needs review', 'wc-invoice-printer' ) );
		echo '<label class="screen-reader-text" for="wcip-print-state">' . esc_html__( 'Invoice print state', 'wc-invoice-printer' ) . '</label><select id="wcip-print-state" name="wcip_print_state">';
		foreach ( $labels as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $this->state(), $key, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select>';
	}
	public function hpos_args( array $args ): array {
		if ( ! current_user_can( 'wcip_view_print_jobs' ) ) { return $args; }
		$args['wcip_prime_print_states'] = true;
		if ( '' === $this->state() ) { return $args; }
		$args['wcip_print_state'] = $this->state();
		return $this->eligibility_args( $args );
	}
	public function query_args( array $args ): array {
		return 'not_printed' === ( $args['wcip_print_state'] ?? '' ) ? $this->eligibility_args( $args ) : $args;
	}
	private function eligibility_args( array $args ): array {
		if ( 'not_printed' !== ( $args['wcip_print_state'] ?? '' ) ) { return $args; }
		$args['status'] = wc_get_is_paid_statuses();
		if ( 'fulfillment' !== ( new \WCInvoicePrinter\Settings\SettingsRepository() )->get( 'automatic_eligibility' ) ) {
			$args['date_paid'] = '>0';
			$offline = array();
			foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
				if ( $gateway instanceof \WC_Gateway_COD || $gateway instanceof \WC_Gateway_BACS || $gateway instanceof \WC_Gateway_Cheque ) { $offline[] = $id; }
			}
			if ( $offline ) {
				if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) { $args['field_query'][] = array( 'field' => 'payment_method', 'value' => $offline, 'compare' => 'NOT IN' ); }
				else { $args['meta_query'][] = array( 'key' => '_payment_method', 'value' => $offline, 'compare' => 'NOT IN' ); }
			}
		}
		// Nonstandard payment adapters can supply equivalent native query predicates.
		return apply_filters( 'wcip_print_filter_query_args', $args );
	}
	public function cpt_args( array $wp_args, array $query_vars ): array {
		if ( isset( $query_vars['wcip_print_state'] ) ) { $wp_args['wcip_print_state'] = $query_vars['wcip_print_state']; }
		if ( 'not_printed' === ( $query_vars['wcip_print_state'] ?? '' ) ) {
			$args = $this->eligibility_args( $query_vars );
			foreach ( $args['meta_query'] ?? array() as $condition ) { $wp_args['meta_query'][] = $condition; }
		}
		return $wp_args;
	}
	public function legacy_query( $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) || '' === $this->state() || ! current_user_can( 'wcip_view_print_jobs' ) ) { return; }
		$query->set( 'wcip_print_state', $this->state() );
		if ( 'not_printed' === $this->state() ) {
			$args = $this->eligibility_args( array( 'wcip_print_state' => 'not_printed' ) );
			$query->set( 'post_status', array_map( static function ( $status ): string { return 'wc-' . $status; }, $args['status'] ) );
			$meta = $query->get( 'meta_query' ) ?: array();
			if ( isset( $args['date_paid'] ) ) { $meta[] = array( 'key' => '_date_paid', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ); }
			foreach ( $args['meta_query'] ?? array() as $condition ) { $meta[] = $condition; }
			$query->set( 'meta_query', $meta );
		}
	}
	public function legacy_where( string $where, $query ): string {
		global $wpdb;
		$state = (string) $query->get( 'wcip_print_state' );
		return $where . $this->condition( $wpdb->posts . '.ID', $state );
	}
	public function hpos_clauses( array $clauses, $query, array $args ): array {
		$state = $args['wcip_print_state'] ?? '';
		if ( '' !== $state ) {
			$table = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name();
			$clauses['where'] .= $this->condition( $table . '.id', $state );
		}
		return $clauses;
	}
	private function condition( string $order_id, string $state ): string {
		global $wpdb;
		if ( ! in_array( $state, array( 'printed', 'not_printed', 'queued', 'processing', 'submitted', 'failed', 'unknown' ), true ) ) { return ''; }
		$table = $wpdb->prefix . 'wc_invoice_print_jobs';
		$history = "SELECT 1 FROM {$table} wcip_confirmed WHERE wcip_confirmed.order_id = {$order_id} AND wcip_confirmed.document_type = 'invoice' AND wcip_confirmed.printed_at IS NOT NULL";
		if ( 'printed' === $state ) { return " AND EXISTS ({$history})"; }
		if ( 'not_printed' === $state ) { return " AND NOT EXISTS (SELECT 1 FROM {$table} wcip_any WHERE wcip_any.order_id = {$order_id} AND wcip_any.document_type = 'invoice')"; }
		return " AND NOT EXISTS ({$history}) AND EXISTS (SELECT 1 FROM {$table} wcip_latest WHERE wcip_latest.order_id = {$order_id} AND wcip_latest.document_type = 'invoice' AND wcip_latest.status = " . $wpdb->prepare( '%s', $state ) . " AND wcip_latest.id = (SELECT MAX(wcip_last.id) FROM {$table} wcip_last WHERE wcip_last.order_id = {$order_id} AND wcip_last.document_type = 'invoice'))";
	}
}
