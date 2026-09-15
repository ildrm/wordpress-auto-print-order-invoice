<?php

namespace WCInvoicePrinter\Pdf;

use WCInvoicePrinter\Template\TemplateDefinition;

interface PdfRendererInterface {
	public function render( string $html, TemplateDefinition $template ): string;
}
