<?php

namespace WCInvoicePrinter\Admin;

use WCInvoicePrinter\PrintJob\PrintJobRepository;
use WCInvoicePrinter\PrintJob\JobStatus;
use WCInvoicePrinter\PrintJob\PrintConfirmationService;
use WCInvoicePrinter\Settings\SettingsRepository;
use WCInvoicePrinter\Template\TemplateRegistry;

final class OrderIntegration {
	private SettingsRepository $settings;
	private TemplateRegistry $templates;
	private PrintJobRepository $jobs;

	public function __construct( SettingsRepository $settings, TemplateRegistry $templates, PrintJobRepository $jobs ) {
		$this->settings = $settings;
		$this->templates = $templates;
		$this->jobs = $jobs;
	}

	public function add_meta_boxes(): void {
		foreach ( array( 'shop_order', 'woocommerce_page_wc-orders' ) as $screen ) {
			add_meta_box( 'wcip-order-print', __( 'Invoice printing', 'wc-invoice-printer' ), array( $this, 'render_meta_box' ), $screen, 'side', 'default' );
		}
	}

	public function columns( array $columns ): array {
		if ( current_user_can( 'wcip_view_print_jobs' ) ) { $columns['wcip_printed'] = __( 'Printed', 'wc-invoice-printer' ); }
		return $columns;
	}

	/** HPOS supplies WC_Order; the legacy screen supplies the post ID. */
	public function render_column( string $column, $object ): void {
		if ( 'wcip_printed' !== $column || ! current_user_can( 'wcip_view_print_jobs' ) ) { return; }
		$order = $object instanceof \WC_Order ? $object : wc_get_order( $object );
		if ( ! $order instanceof \WC_Order ) { return; }
		$confirmed = $this->jobs->latest_confirmed_for_order( $order->get_id() );
		echo '<span title="' . esc_attr__( 'Printed means an operator confirmed the paper output.', 'wc-invoice-printer' ) . '">' . ( $confirmed ? esc_html__( 'Printed', 'wc-invoice-printer' ) : esc_html__( 'Not printed', 'wc-invoice-printer' ) ) . '</span>';
		if ( $confirmed ) { echo '<br><small>' . esc_html( get_date_from_gmt( $confirmed['printed_at'] ) ) . '</small>'; }
	}

	public function render_meta_box( $object ): void {
		$order = $object instanceof \WC_Order ? $object : ( $object instanceof \WP_Post ? wc_get_order( $object->ID ) : null );
		if ( ! $order instanceof \WC_Order || ! current_user_can( 'wcip_print_invoices' ) ) { return; }
		echo '<div data-wcip-print-record><p><strong data-wcip-printed-label>';
		$this->render_column( 'wcip_printed', $order );
		echo '</strong></p>';
		$latest_print = $this->jobs->latest_for_order( $order->get_id() );
		if ( current_user_can( 'wcip_view_print_jobs' ) && $latest_print && empty( $latest_print['printed_at'] ) && PrintConfirmationService::eligible( $latest_print ) ) {
			PrintConfirmationUi::render( array( (int) $latest_print['id'] ) );
		}
		echo '</div>';
		$latest = $this->jobs->latest_for_order( $order->get_id(), 'automatic' );
		if ( $latest ) { echo '<p class="wcip-order-status"><strong>' . esc_html__( 'Automatic job:', 'wc-invoice-printer' ) . '</strong> ' . esc_html( JobStatus::label( $latest['status'] ) ) . '<br><small>' . esc_html( get_date_from_gmt( $latest['updated_at'] ) ) . '</small></p>'; }
		echo '<p><button type="button" class="button button-primary" data-wcip-open-print="' . absint( $order->get_id() ) . '">' . ( $latest ? esc_html__( 'Reprint invoice', 'wc-invoice-printer' ) : esc_html__( 'Print invoice', 'wc-invoice-printer' ) ) . '</button></p><dialog class="wcip-order-dialog" data-order-id="' . absint( $order->get_id() ) . '" data-preview-base="' . esc_url( $this->preview_url( array( $order->get_id() ), '' ) ) . '"><form method="dialog"><div style="display:flex;justify-content:space-between;align-items:center;gap:20px"><h2 style="margin:0">' . esc_html__( 'Print invoice', 'wc-invoice-printer' ) . '</h2><button class="button-link" value="cancel" aria-label="' . esc_attr__( 'Close print dialog', 'wc-invoice-printer' ) . '">×</button></div><p><label for="wcip-order-template"><strong>' . esc_html__( 'Template', 'wc-invoice-printer' ) . '</strong></label><select id="wcip-order-template" style="width:100%">'; foreach ( $this->templates->all() as $template ) { echo '<option value="' . esc_attr( $template->id ) . '" ' . selected( $this->settings->get( 'default_template' ), $template->id, false ) . '>' . esc_html( $template->name ) . '</option>'; } echo '</select></p><p><label for="wcip-order-output"><strong>' . esc_html__( 'Destination', 'wc-invoice-printer' ) . '</strong></label><select id="wcip-order-output" style="width:100%"><option value="browser">' . esc_html__( 'Browser print dialog', 'wc-invoice-printer' ) . '</option>'; if ( $this->settings->api_key() && $this->settings->get( 'printnode_printer_id' ) ) { echo '<option value="printnode">' . esc_html( sprintf( __( 'PrintNode: %s', 'wc-invoice-printer' ), $this->settings->get( 'printnode_printer_name' ) ?: $this->settings->get( 'printnode_printer_id' ) ) ) . '</option>'; } echo '</select></p><p><label for="wcip-order-copies"><strong>' . esc_html__( 'Copies', 'wc-invoice-printer' ) . '</strong></label><input id="wcip-order-copies" type="number" min="1" max="20" value="1" style="width:100%"></p><p class="description" data-wcip-print-help>' . esc_html__( 'Your browser print dialog will open.', 'wc-invoice-printer' ) . ' ' . esc_html__( 'Choose your local printer in the browser dialog. No API key is required.', 'wc-invoice-printer' ) . '</p><div data-wcip-print-status role="status" aria-live="polite"></div><p style="display:flex;justify-content:flex-end;gap:8px"><button type="button" class="button" data-wcip-preview>' . esc_html__( 'Preview', 'wc-invoice-printer' ) . '</button><button type="button" class="button button-primary" data-wcip-submit>' . esc_html__( 'Print', 'wc-invoice-printer' ) . '</button></p></form></dialog>';
		$this->inline_script();
	}

	public function row_actions( array $actions, \WC_Order $order ): array {
		if ( ! current_user_can( 'wcip_print_invoices' ) ) { return $actions; }
		$actions['wcip_print'] = array( 'url' => add_query_arg( 'print', '1', $this->preview_url( array( $order->get_id() ), (string) $this->settings->get( 'default_template', 'classic' ) ) ), 'name' => __( 'Print invoice', 'wc-invoice-printer' ), 'action' => 'wcip-print' );
		return $actions;
	}

	public function bulk_actions( array $actions ): array {
		if ( current_user_can( 'wcip_print_invoices' ) ) { $actions['wcip_bulk_print'] = __( 'Print invoices', 'wc-invoice-printer' ); }
		return $actions;
	}

	public function handle_bulk( string $redirect, string $action, array $order_ids ): string {
		if ( 'wcip_bulk_print' !== $action || ! current_user_can( 'wcip_print_invoices' ) ) { return $redirect; }
		$url = add_query_arg( array( 'page' => 'wc-invoice-printer', 'tab' => 'bulk', 'order_ids' => implode( ',', array_slice( array_map( 'absint', $order_ids ), 0, 50 ) ) ), admin_url( 'admin.php' ) );
		return add_query_arg( '_wpnonce', wp_create_nonce( 'wcip_bulk_print' ), $url );
	}

	private function preview_url( array $order_ids, string $template ): string {
		$url = add_query_arg( array( 'action' => 'wcip_preview', 'order_ids' => implode( ',', $order_ids ), 'template' => $template ?: 'classic' ), admin_url( 'admin-post.php' ) );
		return add_query_arg( '_wpnonce', wp_create_nonce( 'wcip_preview_invoices' ), $url );
	}

	private function inline_script(): void {
		static $done = false;
		if ( $done ) { return; }
		$done = true;
		PrintConfirmationUi::enqueue();
		$config = array( 'rest' => esc_url_raw( rest_url( 'wc-invoice-printer/v1/print' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'printer' => (string) $this->settings->get( 'printnode_printer_id', '' ), 'browserHelp' => __( 'Your browser print dialog will open.', 'wc-invoice-printer' ) . ' ' . __( 'Choose your local printer in the browser dialog. No API key is required.', 'wc-invoice-printer' ), 'nodeHelp' => __( 'The invoice will be queued for the configured physical printer.', 'wc-invoice-printer' ), 'preparing' => __( 'Preparing invoice…', 'wc-invoice-printer' ), 'sending' => __( 'Queuing invoice…', 'wc-invoice-printer' ), 'queued' => __( 'Invoice queued. Check Print Jobs for delivery status.', 'wc-invoice-printer' ), 'failed' => __( 'Printing failed. Review the message and try again.', 'wc-invoice-printer' ), 'invalidCopies' => __( 'Choose between 1 and 20 copies.', 'wc-invoice-printer' ) );
		wp_enqueue_style( 'wcip-admin', WCIP_URL . 'assets/css/admin.css', array(), WCIP_VERSION );
		wp_enqueue_script( 'wcip-order-print', WCIP_URL . 'assets/js/order.js', array(), WCIP_VERSION, true );
		wp_add_inline_script( 'wcip-order-print', 'window.wcipOrderPrint=' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
	}
}
