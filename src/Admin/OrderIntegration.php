<?php

namespace WCInvoicePrinter\Admin;

use WCInvoicePrinter\PrintJob\PrintJobRepository;
use WCInvoicePrinter\Settings\SettingsRepository;
use WCInvoicePrinter\Template\TemplateRegistry;

final class OrderIntegration {
	public function __construct( private readonly SettingsRepository $settings, private readonly TemplateRegistry $templates, private readonly PrintJobRepository $jobs ) {}

	public function add_meta_boxes(): void {
		foreach ( array( 'shop_order', 'woocommerce_page_wc-orders' ) as $screen ) {
			add_meta_box( 'wcip-order-print', __( 'Invoice printing', 'wc-invoice-printer' ), array( $this, 'render_meta_box' ), $screen, 'side', 'default' );
		}
	}

	public function render_meta_box( mixed $object ): void {
		$order = $object instanceof \WC_Order ? $object : ( $object instanceof \WP_Post ? wc_get_order( $object->ID ) : null );
		if ( ! $order || ! current_user_can( 'wcip_print_invoices' ) ) { return; }
		$latest = $this->jobs->latest_for_order( $order->get_id(), 'automatic' );
		if ( $latest ) { echo '<p class="wcip-order-status"><strong>' . esc_html__( 'Automatic job:', 'wc-invoice-printer' ) . '</strong> ' . esc_html( ucfirst( $latest['status'] ) ) . '<br><small>' . esc_html( get_date_from_gmt( $latest['updated_at'] ) ) . '</small></p>'; }
		echo '<p><button type="button" class="button button-primary" data-wcip-open-print="' . absint( $order->get_id() ) . '">' . ( $latest ? esc_html__( 'Reprint invoice', 'wc-invoice-printer' ) : esc_html__( 'Print invoice', 'wc-invoice-printer' ) ) . '</button></p><dialog class="wcip-order-dialog" data-order-id="' . absint( $order->get_id() ) . '" data-preview-base="' . esc_url( $this->preview_url( array( $order->get_id() ), '' ) ) . '"><form method="dialog"><div style="display:flex;justify-content:space-between;align-items:center;gap:20px"><h2 style="margin:0">' . esc_html__( 'Print invoice', 'wc-invoice-printer' ) . '</h2><button class="button-link" value="cancel" aria-label="' . esc_attr__( 'Close print dialog', 'wc-invoice-printer' ) . '">×</button></div><p><label for="wcip-order-template"><strong>' . esc_html__( 'Template', 'wc-invoice-printer' ) . '</strong></label><select id="wcip-order-template" style="width:100%">'; foreach ( $this->templates->all() as $template ) { echo '<option value="' . esc_attr( $template->id ) . '" ' . selected( $this->settings->get( 'default_template' ), $template->id, false ) . '>' . esc_html( $template->name ) . '</option>'; } echo '</select></p><p><label for="wcip-order-output"><strong>' . esc_html__( 'Destination', 'wc-invoice-printer' ) . '</strong></label><select id="wcip-order-output" style="width:100%"><option value="browser">' . esc_html__( 'Browser print dialog', 'wc-invoice-printer' ) . '</option>'; if ( $this->settings->api_key() && $this->settings->get( 'printnode_printer_id' ) ) { echo '<option value="printnode">' . esc_html( sprintf( __( 'PrintNode: %s', 'wc-invoice-printer' ), $this->settings->get( 'printnode_printer_name' ) ?: $this->settings->get( 'printnode_printer_id' ) ) ) . '</option>'; } echo '</select></p><p><label for="wcip-order-copies"><strong>' . esc_html__( 'Copies', 'wc-invoice-printer' ) . '</strong></label><input id="wcip-order-copies" type="number" min="1" max="20" value="1" style="width:100%"></p><p class="description" data-wcip-print-help>' . esc_html__( 'Your browser print dialog will open.', 'wc-invoice-printer' ) . '</p><div data-wcip-print-status role="status" aria-live="polite"></div><p style="display:flex;justify-content:flex-end;gap:8px"><button type="button" class="button" data-wcip-preview>' . esc_html__( 'Preview', 'wc-invoice-printer' ) . '</button><button type="button" class="button button-primary" data-wcip-submit>' . esc_html__( 'Print', 'wc-invoice-printer' ) . '</button></p></form></dialog>';
		$this->inline_script();
	}

	public function row_actions( array $actions, \WC_Order $order ): array {
		if ( ! current_user_can( 'wcip_print_invoices' ) ) { return $actions; }
		$actions['wcip_print'] = array( 'url' => $this->preview_url( array( $order->get_id() ), (string) $this->settings->get( 'default_template', 'classic' ) ), 'name' => __( 'Print invoice', 'wc-invoice-printer' ), 'action' => 'wcip-print' );
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
		static $done = false; if ( $done ) { return; } $done = true;
		$config = array( 'rest' => esc_url_raw( rest_url( 'wc-invoice-printer/v1/print' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'printer' => (string) $this->settings->get( 'printnode_printer_id', '' ), 'browserHelp' => __( 'Your browser print dialog will open.', 'wc-invoice-printer' ), 'nodeHelp' => __( 'The invoice will be sent to the configured physical printer.', 'wc-invoice-printer' ), 'preparing' => __( 'Preparing invoice…', 'wc-invoice-printer' ), 'sending' => __( 'Sending to printer…', 'wc-invoice-printer' ), 'submitted' => __( 'Submitted to PrintNode.', 'wc-invoice-printer' ), 'failed' => __( 'Printing failed. Review the message and try again.', 'wc-invoice-printer' ) );
		echo '<script>window.wcipOrderPrint=' . wp_json_encode( $config ) . ';document.addEventListener("click",async function(e){var open=e.target.closest("[data-wcip-open-print]");if(open){document.querySelector(".wcip-order-dialog").showModal();return}var d=e.target.closest(".wcip-order-dialog");if(!d)return;var preview=e.target.closest("[data-wcip-preview]"),submit=e.target.closest("[data-wcip-submit]");if(!preview&&!submit)return;var t=d.querySelector("#wcip-order-template").value,o=d.querySelector("#wcip-order-output").value,c=parseInt(d.querySelector("#wcip-order-copies").value,10),status=d.querySelector("[data-wcip-print-status]");if(preview||o==="browser"){status.textContent=window.wcipOrderPrint.preparing;var u=new URL(d.dataset.previewBase);u.searchParams.set("template",t);u.searchParams.set("copies",String(c));window.open(u.toString(),"_blank","noopener");status.textContent="";return}submit.disabled=true;status.textContent=window.wcipOrderPrint.sending;try{var r=await fetch(window.wcipOrderPrint.rest,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":window.wcipOrderPrint.nonce},body:JSON.stringify({order_ids:[parseInt(d.dataset.orderId,10)],template_id:t,provider_id:"printnode",printer_id:window.wcipOrderPrint.printer,copies:c})}),data=await r.json();if(!r.ok)throw new Error(data.message||window.wcipOrderPrint.failed);status.textContent=window.wcipOrderPrint.submitted}catch(err){status.textContent=err.message}finally{submit.disabled=false}});document.addEventListener("change",function(e){if(e.target.id!=="wcip-order-output")return;var d=e.target.closest("dialog");d.querySelector("[data-wcip-print-help]").textContent=e.target.value==="browser"?window.wcipOrderPrint.browserHelp:window.wcipOrderPrint.nodeHelp;});</script><style>.wcip-order-dialog{width:min(420px,calc(100vw - 32px));border:1px solid #c3c4c7;border-radius:5px;padding:20px}.wcip-order-dialog::backdrop{background:rgba(29,35,39,.55)}.wcip-order-dialog:focus-visible{outline:2px solid #2271b1}</style>';
	}
}
