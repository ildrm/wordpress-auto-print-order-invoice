<?php

namespace WCInvoicePrinter\Admin;

use WCInvoicePrinter\Settings\SettingsRepository;

final class OperationsConsole {
	private SettingsRepository $settings;
	public function __construct( SettingsRepository $settings ) { $this->settings = $settings; }
	public function render( string $tab ): void {
		$manage = current_user_can( 'wcip_manage_settings' );
		if ( ! $manage && ! ( 'integrations' === $tab && current_user_can( 'wcip_scan_orders' ) ) ) { return; }
		wp_enqueue_script( 'wcip-operations', WCIP_URL . 'assets/js/operations.js', array(), WCIP_VERSION, true );
		wp_localize_script( 'wcip-operations', 'wcipOperations', array( 'rest' => rest_url( 'wc-invoice-printer/v1' ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'working' => __( 'Working…', 'wc-invoice-printer' ), 'failed' => __( 'The operation could not complete. Check permissions, input and configuration, then review diagnostics.', 'wc-invoice-printer' ), 'eligible' => __( 'Eligible', 'wc-invoice-printer' ), 'existing' => __( 'Existing job', 'wc-invoice-printer' ), 'ineligible' => __( 'Ineligible', 'wc-invoice-printer' ), 'notPrinted' => __( 'Not printed', 'wc-invoice-printer' ), 'complete' => __( 'Operation completed.', 'wc-invoice-printer' ), 'order' => __( 'Order', 'wc-invoice-printer' ), 'item' => __( 'Item', 'wc-invoice-printer' ), 'sku' => __( 'SKU', 'wc-invoice-printer' ), 'quantity' => __( 'Quantity', 'wc-invoice-printer' ), 'weight' => __( 'Weight in kg', 'wc-invoice-printer' ), 'unknown' => __( 'Unknown', 'wc-invoice-printer' ) ) );
		echo '<section data-wcip-operations><div role="status" aria-live="polite" data-wcip-operations-status></div>';
		if ( 'shipping' === $tab ) { $this->settings_form( 'shipping' ); }
		elseif ( 'reconciliation' === $tab ) {
			echo '<h2>' . esc_html__( 'Order reconciliation', 'wc-invoice-printer' ) . '</h2><p>' . esc_html__( 'Preview missing automatic invoices before selecting historical orders. Nothing prints during preview. Date bounds use UTC and span at most 31 days.', 'wc-invoice-printer' ) . '</p>';
			echo '<label>' . esc_html__( 'From', 'wc-invoice-printer' ) . ' <input type="date" data-wcip-from value="' . esc_attr( gmdate( 'Y-m-d', time() - 86400 ) ) . '"></label> <label>' . esc_html__( 'To', 'wc-invoice-printer' ) . ' <input type="date" data-wcip-to value="' . esc_attr( gmdate( 'Y-m-d' ) ) . '"></label> <button type="button" class="button" data-wcip-preview-missing>' . esc_html__( 'Preview missing prints', 'wc-invoice-printer' ) . '</button><div data-wcip-missing-list></div><p><label><input type="checkbox" data-wcip-backfill-confirm> ' . esc_html__( 'I reviewed the selected orders and authorize physical print submission.', 'wc-invoice-printer' ) . '</label></p><button type="button" class="button button-primary" data-wcip-enqueue-missing disabled>' . esc_html__( 'Enqueue selected missing invoices', 'wc-invoice-printer' ) . '</button>';
			$this->settings_form( 'reconciliation' );
		} elseif ( 'integrations' === $tab ) {
			if ( current_user_can( 'wcip_scan_orders' ) ) {
				echo '<h2>' . esc_html__( 'Barcode scanner', 'wc-invoice-printer' ) . '</h2><p>' . esc_html__( 'Use a keyboard scanner or enter the opaque reference. Scanning requires sign-in and never confirms physical printing.', 'wc-invoice-printer' ) . '</p><label for="wcip-scan-input">' . esc_html__( 'Document reference', 'wc-invoice-printer' ) . '</label><input id="wcip-scan-input" type="text" autocomplete="off" maxlength="50" data-wcip-scan><button type="button" class="button" data-wcip-scan-submit>' . esc_html__( 'Look up', 'wc-invoice-printer' ) . '</button><div data-wcip-scan-result tabindex="0"></div>';
				echo '<label>' . esc_html__( 'Fulfillment stage', 'wc-invoice-printer' ) . ' <select data-wcip-stage>';
				foreach ( \WCInvoicePrinter\Fulfillment\FulfillmentService::stages() as $stage ) { echo '<option value="' . esc_attr( $stage ) . '">' . esc_html( \WCInvoicePrinter\Fulfillment\FulfillmentService::labels()[ $stage ] ) . '</option>'; }
				echo '</select></label> <button type="button" class="button" data-wcip-stage-submit disabled>' . esc_html__( 'Update fulfillment', 'wc-invoice-printer' ) . '</button>';
				if ( current_user_can( 'wcip_export_orders' ) ) { echo ' <button type="button" class="button" data-wcip-export-submit disabled>' . esc_html__( 'Export scanned order', 'wc-invoice-printer' ) . '</button>'; }
			}
			if ( $manage ) { $this->settings_form( 'integrations' ); }
			if ( current_user_can( 'wcip_export_orders' ) ) { echo '<p><button type="button" class="button" data-wcip-export-test>' . esc_html__( 'Test webhook with demo data', 'wc-invoice-printer' ) . '</button> <button type="button" class="button" data-wcip-export-history>' . esc_html__( 'Export history', 'wc-invoice-printer' ) . '</button></p><pre data-wcip-export-result style="white-space:pre-wrap;overflow-wrap:anywhere"></pre>'; }
		} elseif ( 'diagnostics' === $tab ) {
			echo '<h2>' . esc_html__( 'Diagnostics', 'wc-invoice-printer' ) . '</h2><p>' . esc_html__( 'Review queue health, discovery progress and printer configuration. Cron-disabled sites need an external scheduled runner.', 'wc-invoice-printer' ) . '</p><button type="button" class="button" data-wcip-diagnostics>' . esc_html__( 'Refresh diagnostics', 'wc-invoice-printer' ) . '</button><pre data-wcip-diagnostics-result style="white-space:pre-wrap;overflow-wrap:anywhere"></pre>';
		}
		echo '</section>';
	}
	private function settings_form( string $section ): void {
		$s = $this->settings->all();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wcip-form"><input type="hidden" name="action" value="wcip_save_operations"><input type="hidden" name="section" value="' . esc_attr( $section ) . '">';
		wp_nonce_field( 'wcip_save_operations' );
		if ( 'shipping' === $section ) {
			$this->check( 'shipping_billing_fallback', __( 'Use billing values for missing recipient fields', 'wc-invoice-printer' ), $s );
			$this->check( 'show_paid_date', __( 'Show payment date and time', 'wc-invoice-printer' ), $s );
			$this->check( 'show_shipping_phone', __( 'Show recipient phone', 'wc-invoice-printer' ), $s );
			$this->check( 'label_show_sender', __( 'Show sender on labels', 'wc-invoice-printer' ), $s );
			$this->field( 'document_timezone', __( 'Document timezone', 'wc-invoice-printer' ), $s['document_timezone'] );
			$this->field( 'shipping_field_mapping', __( 'Shipping metadata mapping as JSON', 'wc-invoice-printer' ), $s['shipping_field_mapping'] );
			echo '<p class="description">' . esc_html__( 'Use site or an IANA timezone. Map named fields to trusted order metadata keys. Extra address fields are printed only when mapped.', 'wc-invoice-printer' ) . '</p>';
			$this->check( 'fulfillment_on_confirmation', __( 'Start preparation after physical invoice confirmation', 'wc-invoice-printer' ), $s );
			echo '<p><label>' . esc_html__( 'Stage after invoice confirmation', 'wc-invoice-printer' ) . ' <select name="fulfillment_confirmation_stage">';
			foreach ( array( 'preparing', 'packed', 'ready_to_ship' ) as $stage ) { echo '<option value="' . esc_attr( $stage ) . '" ' . selected( $s['fulfillment_confirmation_stage'], $stage, false ) . '>' . esc_html( \WCInvoicePrinter\Fulfillment\FulfillmentService::labels()[ $stage ] ) . '</option>'; }
			echo '</select></label></p>';
			echo '<p>' . esc_html__( 'Fulfillment uses separate plugin storage. Native WooCommerce payment, order status and dates are unchanged.', 'wc-invoice-printer' ) . '</p>';
		} elseif ( 'reconciliation' === $section ) {
			echo '<label for="wcip-eligibility">' . esc_html__( 'Automatic eligibility', 'wc-invoice-printer' ) . '</label><select id="wcip-eligibility" name="automatic_eligibility"><option value="confirmed_payment" ' . selected( $s['automatic_eligibility'], 'confirmed_payment', false ) . '>' . esc_html__( 'Recorded payment confirmed', 'wc-invoice-printer' ) . '</option><option value="fulfillment" ' . selected( $s['automatic_eligibility'], 'fulfillment', false ) . '>' . esc_html__( 'Fulfillment eligible including unpaid collection methods', 'wc-invoice-printer' ) . '</option></select>';
			$this->field( 'reconciliation_interval', __( 'Discovery interval in seconds 300 to 3600', 'wc-invoice-printer' ), $s['reconciliation_interval'], 'number' );
			echo '<p>' . esc_html__( 'Enabling automation starts discovery from that time. Older payments require explicit preview and selection. A gateway adapter may be needed for partial payments or missing payment dates.', 'wc-invoice-printer' ) . '</p>';
		} else {
			$this->check( 'barcode_enabled', __( 'Include opaque document codes', 'wc-invoice-printer' ), $s );
			echo '<label>' . esc_html__( 'Code format', 'wc-invoice-printer' ) . ' <select name="barcode_type"><option value="Code128" ' . selected( $s['barcode_type'], 'Code128', false ) . '>Code 128</option><option value="QR" ' . selected( $s['barcode_type'], 'QR', false ) . '>QR</option></select></label>';
			echo '<p>' . esc_html__( 'For 58 mm receipts choose QR. Code 128 is shown on wider paper only.', 'wc-invoice-printer' ) . '</p>';
			$this->check( 'export_enabled', __( 'Enable generic webhook export', 'wc-invoice-printer' ), $s );
			$this->field( 'export_endpoint', __( 'HTTPS export endpoint', 'wc-invoice-printer' ), $s['export_endpoint'], 'url' );
			$this->field( 'export_allowed_host', __( 'Allowed destination hostname', 'wc-invoice-printer' ), $s['export_allowed_host'] );
			$this->field( 'export_signing_secret', __( 'Replace signing secret leave blank to retain', 'wc-invoice-printer' ), '', 'password' );
			$this->check( 'export_include_recipient', __( 'Share recipient data with the configured receiver', 'wc-invoice-printer' ), $s );
			$this->check( 'export_on_scan', __( 'Export on authorized scan once per reference', 'wc-invoice-printer' ), $s );
			$this->check( 'export_on_packed', __( 'Export on confirmed packed transition', 'wc-invoice-printer' ), $s );
			echo '<p>' . esc_html__( 'Exports are off by default. The receiver must implement the documented HMAC signature, replay protection and idempotency receipt contract. Acceptance does not prove shipping or picking. Credentials are not encrypted in the database; use WCIP_EXPORT_SIGNING_SECRET for managed configuration.', 'wc-invoice-printer' ) . '</p>';
		}
		submit_button(); echo '</form>';
	}
	private function check( string $key, string $label, array $settings ): void { echo '<p><label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( $settings[ $key ], true, false ) . '> ' . esc_html( $label ) . '</label></p>'; }
	private function field( string $key, string $label, $value, string $type = 'text' ): void { echo '<p><label for="wcip-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><br><input class="regular-text" id="wcip-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( (string) $value ) . '" autocomplete="' . ( 'password' === $type ? 'new-password' : 'off' ) . '"></p>'; }
	public function save(): void {
		if ( ! current_user_can( 'wcip_manage_settings' ) ) { wp_die( esc_html__( 'You are not allowed to change these settings.', 'wc-invoice-printer' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'wcip_save_operations' );
		foreach ( $_POST as $value ) { if ( ! is_scalar( $value ) ) { wp_die( esc_html__( 'Invalid settings input.', 'wc-invoice-printer' ), '', array( 'response' => 400 ) ); } }
		$section = sanitize_key( wp_unslash( $_POST['section'] ?? '' ) );
		$fields = array( 'shipping' => array( 'shipping_billing_fallback', 'show_paid_date', 'show_shipping_phone', 'label_show_sender', 'document_timezone', 'shipping_field_mapping', 'fulfillment_on_confirmation', 'fulfillment_confirmation_stage' ), 'reconciliation' => array( 'automatic_eligibility', 'reconciliation_interval' ), 'integrations' => array( 'barcode_enabled', 'barcode_type', 'export_enabled', 'export_endpoint', 'export_allowed_host', 'export_signing_secret', 'export_include_recipient', 'export_on_scan', 'export_on_packed' ) );
		if ( ! isset( $fields[ $section ] ) ) { wp_die( esc_html__( 'Invalid settings section.', 'wc-invoice-printer' ), '', array( 'response' => 400 ) ); }
		$changes = array();
		foreach ( $fields[ $section ] as $key ) { $changes[ $key ] = wp_unslash( $_POST[ $key ] ?? '' ); }
		if ( '' === ( $changes['export_signing_secret'] ?? '' ) || $this->settings->export_secret_is_external() ) { unset( $changes['export_signing_secret'] ); }
		if ( isset( $changes['shipping_field_mapping'] ) && ( ! is_array( json_decode( $changes['shipping_field_mapping'], true ) ) || count( json_decode( $changes['shipping_field_mapping'], true ) ) > 20 ) ) { wp_die( esc_html__( 'Invalid settings input.', 'wc-invoice-printer' ), '', array( 'response' => 400 ) ); }
		$this->settings->update( $changes );
		if ( 'reconciliation' === $section && function_exists( 'as_unschedule_all_actions' ) ) { as_unschedule_all_actions( 'wcip_reconcile_paid_orders', array(), 'wc-invoice-printer' ); }
		wp_safe_redirect( add_query_arg( array( 'page' => 'wc-invoice-printer', 'tab' => $section, 'updated' => 1 ), admin_url( 'admin.php' ) ) ); exit;
	}
}
