<?php

namespace WCInvoicePrinter\Template;

use WCInvoicePrinter\Invoice\InvoiceData;

final class HtmlRenderer {
	public function __construct( private readonly TemplateRegistry $templates ) {}

	public function render( InvoiceData $invoice, string $template_id ): string {
		$template = $this->templates->get( $template_id );
		do_action( 'wcip_before_invoice_render', $invoice, $template );
		$buffer_level = ob_get_level();
		ob_start();
		try {
			include $template->path;
			$html = (string) ob_get_clean();
		} finally {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
		}
		$html = (string) apply_filters( 'wcip_rendered_invoice_html', $html, $invoice, $template );
		do_action( 'wcip_after_invoice_render', $html, $invoice, $template );
		return $html;
	}
}
