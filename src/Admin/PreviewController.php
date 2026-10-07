<?php

namespace WCInvoicePrinter\Admin;

use WCInvoicePrinter\Invoice\InvoiceFactory;
use WCInvoicePrinter\PrintJob\PrintJobRepository;
use WCInvoicePrinter\PrintJob\PrintJobService;
use WCInvoicePrinter\Template\HtmlRenderer;
use WCInvoicePrinter\Template\TemplateRegistry;

final class PreviewController {
	private InvoiceFactory $invoices;
	private HtmlRenderer $html;
	private TemplateRegistry $templates;
	private PrintJobService $job_service;
	private PrintJobRepository $jobs;

	public function __construct(
		InvoiceFactory $invoices,
		HtmlRenderer $html,
		TemplateRegistry $templates,
		PrintJobService $job_service,
		PrintJobRepository $jobs
	) {
		$this->invoices = $invoices;
		$this->html = $html;
		$this->templates = $templates;
		$this->job_service = $job_service;
		$this->jobs = $jobs;
	}

	public function output(): void {
		if ( ! current_user_can( 'wcip_print_invoices' ) ) {
			wp_die( esc_html__( 'You are not allowed to print invoices.', 'wc-invoice-printer' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wcip_preview_invoices' );
		foreach ( array( 'order_ids', 'template', 'copies', 'sample', 'preview', 'rtl', 'print' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && ! is_scalar( $_GET[ $key ] ) ) { wp_die( esc_html__( 'Invalid preview input.', 'wc-invoice-printer' ), '', array( 'response' => 400 ) ); }
		}
		$selection   = isset( $_GET['order_ids'] ) && is_string( $_GET['order_ids'] ) ? sanitize_text_field( wp_unslash( $_GET['order_ids'] ) ) : '';
		$ids         = '' !== $selection ? array_unique( explode( ',', $selection ) ) : array();
		$template_id = isset( $_GET['template'] ) && is_string( $_GET['template'] ) ? sanitize_key( wp_unslash( $_GET['template'] ) ) : 'classic';
		$rtl         = isset( $_GET['rtl'] ) && '1' === $_GET['rtl'];
		$sample      = isset( $_GET['sample'] ) && '1' === $_GET['sample'];
		$preview     = $sample || ( isset( $_GET['preview'] ) && '1' === $_GET['preview'] );
		$auto_print  = ! $preview && isset( $_GET['print'] ) && '1' === $_GET['print'];
		$copies      = $_GET['copies'] ?? '1';
		if ( ! preg_match( '/^(?:[1-9]|1[0-9]|20)$/D', (string) $copies ) ) { wp_die( esc_html__( 'Choose between 1 and 20 copies.', 'wc-invoice-printer' ), '', array( 'response' => 400 ) ); }
		$copies      = (int) $copies;
		$copies      = $preview ? 1 : $copies;
		if ( ! $this->templates->has( $template_id ) ) {
			wp_die( esc_html__( 'Invalid invoice template.', 'wc-invoice-printer' ), '', array( 'response' => 400 ) );
		}
		$documents = array();
		$orders    = array();
		$prepared_jobs = array();
		if ( ! $sample ) {
			if ( count( $ids ) > 50 ) { wp_die( esc_html__( 'Select no more than 50 orders.', 'wc-invoice-printer' ), '', array( 'response' => 400 ) ); }
			foreach ( $ids as $id ) {
				if ( ! preg_match( '/^[1-9][0-9]*$/D', $id ) ) { wp_die( esc_html__( 'Invalid order selection.', 'wc-invoice-printer' ), '', array( 'response' => 400 ) ); }
				$order = wc_get_order( $id );
				if ( ! $order instanceof \WC_Order || in_array( $order->get_status(), array( 'trash', 'checkout-draft' ), true ) ) {
					wp_die( esc_html__( 'One of the selected orders does not exist or cannot be printed.', 'wc-invoice-printer' ), '', array( 'response' => 404 ) );
				}
				$orders[] = $order;
			}
		}
		try {
			if ( $sample ) { $documents[] = $this->html->render( $this->invoices->sample( $rtl || is_rtl() ), $template_id ); }
			foreach ( $orders as $order ) {
				$document = $this->html->render( $this->invoices->from_order( $order )->with_rtl( $rtl || is_rtl() ), $template_id );
				for ( $copy = 0; $copy < $copies; $copy++ ) { $documents[] = $document; }
			}
			// Only record browser jobs after every invoice rendered successfully.
			if ( ! $preview ) {
				foreach ( $orders as $order ) {
					$job = $this->job_service->create_manual( $order, $template_id, 'browser', '', $copies );
					if ( ! $this->jobs->browser_ready( (int) $job['id'] ) ) {
						throw new \RuntimeException( 'The browser job could not be recorded as prepared.' );
					}
					$prepared_jobs[] = (int) $job['id'];
				}
			}
		} catch ( \Throwable $error ) {
			wp_die( esc_html__( 'The invoice could not be prepared. Review Print Jobs before trying again.', 'wc-invoice-printer' ), '', array( 'response' => 500 ) );
		}
		if ( ! $documents ) {
			wp_die( esc_html__( 'No valid invoices were selected.', 'wc-invoice-printer' ), '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		preg_match_all( '/<style[^>]*>(.*?)<\/style>/si', $documents[0], $style_matches );
		$styles = implode( "\n", $style_matches[1] ?? array() );
		$body   = array_map( static function ( string $document ): string { preg_match( '/<body[^>]*>(.*?)<\/body>/si', $document, $match ); return $match[1] ?? ''; }, $documents );
		echo '<!doctype html><html lang="' . esc_attr( \WCInvoicePrinter\I18n\Locale::language_tag() ) . '" dir="' . ( $rtl || is_rtl() ? 'rtl' : 'ltr' ) . '"><head><meta charset="utf-8"><style>' . $styles . '.wcip-toolbar{position:sticky;top:0;z-index:5;padding:10px;background:#fff;border-bottom:1px solid #ccc;text-align:center}.wcip-document{page-break-after:always}.wcip-document:last-child{page-break-after:auto}@media print{.wcip-toolbar{display:none}}</style></head><body>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS originates from bundled, validated templates.
		// Printing a real preview prepares a tracked job only when Print is clicked.
		echo '<div class="wcip-toolbar">';
		if ( $preview && ! $sample ) {
			$url = wp_nonce_url( add_query_arg( array( 'action' => 'wcip_preview', 'order_ids' => implode( ',', array_map( static function ( \WC_Order $order ): int { return $order->get_id(); }, $orders ) ), 'template' => $template_id, 'copies' => 1, 'rtl' => $rtl ? 1 : 0, 'print' => 1 ), admin_url( 'admin-post.php' ) ), 'wcip_preview_invoices' );
			echo '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Print', 'wc-invoice-printer' ) . '</a>';
		} else {
			echo '<button onclick="window.print()">' . esc_html__( 'Print', 'wc-invoice-printer' ) . '</button>';
		}
		if ( $prepared_jobs && current_user_can( 'wcip_view_print_jobs' ) ) {
			// A separate, explicit action: afterprint also fires when printing is cancelled.
			PrintConfirmationUi::render( $prepared_jobs );
			echo '<script src="' . esc_url( WCIP_URL . 'assets/js/confirmation.js?ver=' . WCIP_VERSION ) . '" defer></script>';
		}
		echo '</div>';
		foreach ( $body as $document ) { echo '<section class="wcip-document">' . $document . '</section>'; } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered template escapes normalized data.
		if ( $auto_print ) { echo '<script>window.addEventListener("load",function(){window.print();});</script>'; }
		echo '</body></html>';
		exit;
	}
}
