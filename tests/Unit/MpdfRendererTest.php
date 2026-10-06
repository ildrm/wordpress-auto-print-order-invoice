<?php

namespace WCInvoicePrinter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WCInvoicePrinter\Invoice\InvoiceFactory;
use WCInvoicePrinter\Invoice\InvoiceData;
use WCInvoicePrinter\Pdf\MpdfRenderer;
use WCInvoicePrinter\Settings\SettingsRepository;
use WCInvoicePrinter\Template\TemplateDefinition;
use WCInvoicePrinter\Template\TemplateRegistry;
use WCInvoicePrinter\Template\HtmlRenderer;

final class MpdfRendererTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wcip_test_filters'] = array();
		unset( $GLOBALS['wcip_temp_dir'] );
	}
	protected function tearDown(): void { unset( $GLOBALS['wcip_temp_dir'] ); }
	public function test_unicode_rtl_document_renders_to_pdf_bytes(): void {
		$template = new TemplateDefinition( 'test', 'Test', 'Test', 'A4', 'portrait', true, __FILE__ );
		$pdf = ( new MpdfRenderer() )->render( '<html dir="rtl"><body><h1>فاکتور فروش</h1><p>محصول آزمایشی — Order 42</p></body></html>', $template );
		self::assertStringStartsWith( '%PDF-', $pdf );
		self::assertGreaterThan( 1000, strlen( $pdf ) );
	}

	#[DataProvider( 'built_in_templates' )]
	public function test_real_template_pdf_uses_its_advertised_paper_size( string $id, bool $rtl ): void {
		$templates = new TemplateRegistry();
		$invoice = ( new InvoiceFactory( new SettingsRepository() ) )->sample( $rtl );
		$html = ( new HtmlRenderer( $templates ) )->render( $invoice, $id );
		$pdf = ( new MpdfRenderer() )->render( $html, $templates->get( $id ) );
		self::assertStringStartsWith( '%PDF-', $pdf );
		self::assertSame( 1, preg_match( '/\/MediaBox\s*\[\s*0\s+0\s+([0-9.]+)\s+([0-9.]+)\s*\]/', $pdf, $dimensions ) );
		self::assertEqualsWithDelta( 'thermal' === $id ? 80 : 210, (float) $dimensions[1] * 25.4 / 72, 0.02 );
		self::assertEqualsWithDelta( 'thermal' === $id ? 199 : 297, (float) $dimensions[2] * 25.4 / 72, 0.02 );
	}

	public static function built_in_templates(): array {
		return array( array( 'classic', false ), array( 'compact', false ), array( 'thermal', false ), array( 'classic', true ), array( 'compact', true ), array( 'thermal', true ) );
	}

	public function test_large_receipt_height_is_bounded_and_keeps_the_80mm_width(): void {
		$templates = new TemplateRegistry();
		$sample = ( new InvoiceFactory( new SettingsRepository() ) )->sample();
		$items = array_fill( 0, 45, $sample->items[0] );
		$invoice = new InvoiceData( $sample->order, $sample->store, $sample->customer, $items, $sample->totals, $sample->fulfillment );
		$html = ( new HtmlRenderer( $templates ) )->render( $invoice, 'thermal' );
		$pdf = ( new MpdfRenderer() )->render( $html, $templates->get( 'thermal' ) );
		self::assertSame( 1, preg_match( '/\/MediaBox\s*\[\s*0\s+0\s+([0-9.]+)\s+([0-9.]+)\s*\]/', $pdf, $dimensions ) );
		self::assertEqualsWithDelta( 80, (float) $dimensions[1] * 25.4 / 72, 0.02 );
		self::assertEqualsWithDelta( 1000, (float) $dimensions[2] * 25.4 / 72, 0.02 );
	}

	public function test_pdf_temporary_files_are_removed_after_success(): void {
		$directory = sys_get_temp_dir() . '/wcip-pdf-test-' . bin2hex( random_bytes( 8 ) );
		mkdir( $directory, 0700 );
		$GLOBALS['wcip_temp_dir'] = $directory;
		try {
			$template = new TemplateDefinition( 'test', 'Test', '', 'A4', 'portrait', true, __FILE__ );
			self::assertStringStartsWith( '%PDF-', ( new MpdfRenderer() )->render( '<p>Private invoice</p>', $template ) );
			self::assertSame( array( '.', '..' ), scandir( $directory ) );
		} finally { rmdir( $directory ); }
	}

	public function test_pdf_temporary_files_are_removed_after_generation_failure(): void {
		$directory = sys_get_temp_dir() . '/wcip-pdf-test-' . bin2hex( random_bytes( 8 ) );
		mkdir( $directory, 0700 );
		$GLOBALS['wcip_temp_dir'] = $directory;
		try {
			$template = new TemplateDefinition( 'test', 'Test', '', 'invalid-paper-format', 'portrait', true, __FILE__ );
			try {
				( new MpdfRenderer() )->render( '<p>Private invoice</p>', $template );
				self::fail( 'Expected PDF generation failure.' );
			} catch ( \Mpdf\MpdfException ) {
				self::assertSame( array( '.', '..' ), scandir( $directory ) );
			}
		} finally { rmdir( $directory ); }
	}

	public function test_unavailable_temporary_root_fails_before_rendering(): void {
		$GLOBALS['wcip_temp_dir'] = sys_get_temp_dir() . '/missing-wcip-directory-' . bin2hex( random_bytes( 8 ) );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'A secure PDF working directory could not be created.' );
		( new MpdfRenderer() )->render( '<p>Invoice</p>', new TemplateDefinition( 'test', 'Test', '', 'A4', 'portrait', true, __FILE__ ) );
	}
}
