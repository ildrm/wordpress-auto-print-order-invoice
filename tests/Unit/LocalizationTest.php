<?php

namespace WCInvoicePrinter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCInvoicePrinter\I18n\Locale;
use WCInvoicePrinter\Invoice\InvoiceFactory;
use WCInvoicePrinter\Pdf\MpdfRenderer;
use WCInvoicePrinter\PrintJob\JobStatus;
use WCInvoicePrinter\Settings\SettingsRepository;
use WCInvoicePrinter\Template\HtmlRenderer;
use WCInvoicePrinter\Template\TemplateRegistry;
use WCInvoicePrinter\Tests\Support\TranslationCatalog;

final class LocalizationTest extends TestCase {
	protected function setUp(): void { $GLOBALS['wcip_test_filters'] = array(); }
	protected function tearDown(): void {
		unset( $GLOBALS['wcip_test_translations'], $GLOBALS['wcip_test_locale'], $GLOBALS['wcip_test_user_locale'] );
	}

	/** @dataProvider locales */
	public function test_translated_catalog_renders_all_invoice_layouts( string $locale, string $invoice_label ): void {
		$GLOBALS['wcip_test_translations'] = TranslationCatalog::load( $locale );
		$GLOBALS['wcip_test_locale'] = $locale;
		$templates = new TemplateRegistry();
		$invoice = ( new InvoiceFactory( new SettingsRepository() ) )->sample( is_rtl() );
		$renderer = new HtmlRenderer( $templates );
		self::assertSame( $invoice_label, __( 'Invoice', 'wc-invoice-printer' ) );
		self::assertSame( __( 'Queued', 'wc-invoice-printer' ), JobStatus::label( JobStatus::QUEUED ) );
		self::assertSame( 'queued', JobStatus::QUEUED );
		foreach ( array( 'classic', 'compact', 'thermal' ) as $id ) {
			$html = $renderer->render( $invoice, $id );
			self::assertStringContainsString( 'compact' === $id ? $invoice_label : __( 'INVOICE', 'wc-invoice-printer' ), $html );
			self::assertStringContainsString( 'lang="' . str_replace( '_', '-', $locale ) . '"', $html );
			self::assertStringContainsString( 'dir="' . ( is_rtl() ? 'rtl' : 'ltr' ) . '"', $html );
			$pdf = ( new MpdfRenderer() )->render( $html, $templates->get( $id ) );
			self::assertStringStartsWith( '%PDF-', $pdf );
			self::assertGreaterThan( 1000, strlen( $pdf ) );
			if ( in_array( $locale, array( 'zh_CN', 'ja' ), true ) ) {
				self::assertStringContainsString( 'Sun-ExtA', $pdf, 'CJK glyphs require the bundled CJK font.' );
			} elseif ( 'hi_IN' === $locale ) {
				self::assertStringContainsString( 'FreeSerif', $pdf, 'Hindi requires a font with Indic shaping support.' );
			}
		}
	}

	public function test_invoice_language_tag_respects_admin_user_locale(): void {
		$GLOBALS['wcip_test_locale'] = 'en_US';
		$GLOBALS['wcip_test_user_locale'] = 'fa_IR';
		self::assertSame( 'fa-IR', Locale::language_tag() );
	}

	public static function locales(): array {
		return array(
			array( 'en_US', 'Invoice' ), array( 'fa_IR', 'فاکتور' ), array( 'tr_TR', 'Fatura' ),
			array( 'ar', 'فاتورة' ), array( 'fr_FR', 'Facture' ), array( 'de_DE', 'Rechnung' ),
			array( 'ru_RU', 'Счёт' ), array( 'es_ES', 'Factura' ), array( 'pt_PT', 'Fatura' ),
			array( 'pt_BR', 'Fatura' ), array( 'hy', 'Հաշիվ' ), array( 'hi_IN', 'इनवॉइस' ),
			array( 'zh_CN', '发票' ), array( 'ja', '請求書' ),
		);
	}
}
