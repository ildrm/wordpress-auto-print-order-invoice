<?php

namespace WCInvoicePrinter\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WCInvoicePrinter\Invoice\InvoiceData;
use WCInvoicePrinter\Invoice\InvoiceFactory;
use WCInvoicePrinter\Settings\SettingsRepository;
use WCInvoicePrinter\Template\HtmlRenderer;
use WCInvoicePrinter\Template\TemplateRegistry;

final class TemplateRenderTest extends TestCase {
	#[DataProvider( 'templates' )]
	public function test_template_renders_complete_rtl_invoice( string $template_id ): void {
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

	public static function templates(): array {
		return array( array( 'classic' ), array( 'compact' ), array( 'thermal' ) );
	}
}
