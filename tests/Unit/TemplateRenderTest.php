<?php

namespace WCInvoicePrinter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCInvoicePrinter\Invoice\InvoiceData;
use WCInvoicePrinter\Invoice\InvoiceFactory;
use WCInvoicePrinter\Settings\SettingsRepository;
use WCInvoicePrinter\Template\HtmlRenderer;
use WCInvoicePrinter\Template\TemplateRegistry;
use WCInvoicePrinter\Template\TemplateDefinition;
use WCInvoicePrinter\Tests\Support\TranslationCatalog;

final class TemplateRenderTest extends TestCase {
	protected function setUp(): void { $GLOBALS['wcip_test_filters'] = array(); }
	protected function tearDown(): void {
		$GLOBALS['wcip_test_filters'] = array();
		unset( $GLOBALS['wcip_test_translations'], $GLOBALS['wcip_test_locale'] );
	}
	/**
	 * @dataProvider templates
	 */
	public function test_template_renders_complete_rtl_invoice( string $template_id ): void {
		$GLOBALS['wcip_test_translations'] = TranslationCatalog::load( 'fa_IR' );
		$GLOBALS['wcip_test_locale'] = 'fa_IR';
		$renderer = new HtmlRenderer( new TemplateRegistry() );
		$invoice  = ( new InvoiceFactory( new SettingsRepository() ) )->sample( true );
		$html     = $renderer->render( $invoice, $template_id );
		self::assertStringContainsString( 'dir="rtl"', $html );
		self::assertStringContainsString( 'آرمان رضایی', $html );
		self::assertStringContainsString( 'دفتر برنامه‌ریزی حرفه‌ای', $html );
		self::assertStringContainsString( '$108.09', $html );
	}

	public function test_customer_html_is_contextually_escaped(): void {
		$factory  = new InvoiceFactory( new SettingsRepository() );
		$sample   = $factory->sample();
		$customer = $sample->customer;
		$customer['name'] = '<script>alert(1)</script>Alex';
		$invoice = new InvoiceData( $sample->order, $sample->store, $customer, $sample->items, $sample->totals, $sample->fulfillment );
		$html = ( new HtmlRenderer( new TemplateRegistry() ) )->render( $invoice, 'classic' );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_template_exception_discards_partial_output_and_restores_buffer_level(): void {
		$path = tempnam( sys_get_temp_dir(), 'wcip-template-test-' );
		file_put_contents( $path, '<?php echo "private partial invoice"; throw new RuntimeException("template error");' );
		$templates = new TemplateRegistry();
		$templates->register( new TemplateDefinition( 'throwing', 'Throwing', '', 'A4', 'portrait', true, $path ) );
		$level = ob_get_level();
		try {
			( new HtmlRenderer( $templates ) )->render( ( new InvoiceFactory( new SettingsRepository() ) )->sample(), 'throwing' );
			self::fail( 'Expected template exception.' );
		} catch ( \RuntimeException $error ) {
			self::assertSame( 'template error', $error->getMessage() );
			self::assertSame( $level, ob_get_level() );
		} finally {
			unlink( $path );
		}
	}

	public static function templates(): array {
		return array( array( 'classic' ), array( 'compact' ), array( 'thermal' ) );
	}
}
