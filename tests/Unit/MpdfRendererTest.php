<?php

namespace WCInvoicePrinter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCInvoicePrinter\Pdf\MpdfRenderer;
use WCInvoicePrinter\Template\TemplateDefinition;

final class MpdfRendererTest extends TestCase {
	public function test_unicode_rtl_document_renders_to_pdf_bytes(): void {
		$template = new TemplateDefinition( 'test', 'Test', 'Test', 'A4', 'portrait', true, __FILE__ );
		$pdf = ( new MpdfRenderer() )->render( '<html dir="rtl"><body><h1>فاکتور فروش</h1><p>محصول آزمایشی — Order 42</p></body></html>', $template );
		self::assertStringStartsWith( '%PDF-', $pdf );
		self::assertGreaterThan( 1000, strlen( $pdf ) );
	}
}
