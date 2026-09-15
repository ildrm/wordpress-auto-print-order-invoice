<?php

namespace WCInvoicePrinter\Admin;

use WCInvoicePrinter\Invoice\InvoiceFactory;
use WCInvoicePrinter\PrintJob\PrintJobRepository;
use WCInvoicePrinter\PrintJob\PrintJobService;
use WCInvoicePrinter\Template\HtmlRenderer;
use WCInvoicePrinter\Template\TemplateRegistry;

final class PreviewController {
	public function __construct( private readonly InvoiceFactory $invoices, private readonly HtmlRenderer $html, private readonly TemplateRegistry $templates, private readonly PrintJobService $job_service, private readonly PrintJobRepository $jobs ) {}

	public function output(): void {
		if ( ! current_user_can( 'wcip_print_invoices' ) ) {
			wp_die( esc_html__( 'You are not allowed to print invoices.', 'wc-invoice-printer' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wcip_preview_invoices' );
		$ids         = isset( $_GET['order_ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['order_ids'] ) ) ) ) ) : array();
		$template_id = isset( $_GET['template'] ) ? sanitize_key( wp_unslash( $_GET['template'] ) ) : 'classic';
		$rtl         = isset( $_GET['rtl'] ) && '1' === $_GET['rtl'];
		$sample      = isset( $_GET['sample'] ) && '1' === $_GET['sample'];
		$copies      = max( 1, min( 20, absint( $_GET['copies'] ?? 1 ) ) );
		if ( ! $this->templates->has( $template_id ) ) {
			wp_die( esc_html__( 'Invalid invoice template.', 'wc-invoice-printer' ), '', array( 'response' => 400 ) );
		}
		$documents = array();
		if ( $sample ) {
			$documents[] = $this->html->render( $this->invoices->sample( $rtl ), $template_id );
		} else {
			foreach ( array_slice( array_unique( $ids ), 0, 50 ) as $id ) {
				$order = wc_get_order( $id );
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}
				$job = $this->job_service->create_manual( $order, $template_id, 'browser', '', $copies );
				$this->jobs->browser_ready( (int) $job['id'] );
				$document = $this->html->render( $this->invoices->from_order( $order )->with_rtl( $rtl || is_rtl() ), $template_id );
				for ( $copy = 0; $copy < $copies; $copy++ ) { $documents[] = $document; }
			}
		}
		if ( ! $documents ) {
			wp_die( esc_html__( 'No valid invoices were selected.', 'wc-invoice-printer' ), '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		preg_match_all( '/<style[^>]*>(.*?)<\/style>/si', $documents[0], $style_matches );
		$styles = implode( "\n", $style_matches[1] ?? array() );
		$body   = array_map( static function ( string $document ): string { preg_match( '/<body[^>]*>(.*?)<\/body>/si', $document, $match ); return $match[1] ?? ''; }, $documents );
		echo '<!doctype html><html dir="' . ( $rtl || is_rtl() ? 'rtl' : 'ltr' ) . '"><head><meta charset="utf-8"><style>' . $styles . '.wcip-toolbar{position:sticky;top:0;z-index:5;padding:10px;background:#fff;border-bottom:1px solid #ccc;text-align:center}.wcip-document{page-break-after:always}.wcip-document:last-child{page-break-after:auto}@media print{.wcip-toolbar{display:none}}</style></head><body><div class="wcip-toolbar"><button onclick="window.print()">' . esc_html__( 'Print', 'wc-invoice-printer' ) . '</button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS originates from bundled, validated templates.
		foreach ( $body as $document ) { echo '<section class="wcip-document">' . $document . '</section>'; } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered template escapes normalized data.
		echo '</body></html>';
		exit;
	}
}
