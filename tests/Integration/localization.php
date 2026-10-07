<?php
/**
 * Run with WP-CLI on a disposable site with every catalog's core language installed.
 * WCIP_RUN_INTEGRATION_TESTS=1 wp eval-file tests/Integration/localization.php
 */

if ( '1' !== getenv( 'WCIP_RUN_INTEGRATION_TESTS' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run this test explicitly on a disposable WordPress site.' );
}
if ( ! class_exists( \WCInvoicePrinter\Plugin::class ) || ! class_exists( 'WooCommerce' ) ) {
	throw new RuntimeException( 'Activate WooCommerce and WooCommerce Invoice Printer first.' );
}
if ( ! empty( $GLOBALS['wcip_i18n_early'] ) ) {
	throw new RuntimeException( 'The plugin loaded translations before init.' );
}
if ( ! did_action( 'action_scheduler_init' ) || ! has_action( 'action_scheduler_init' ) ) {
	throw new RuntimeException( 'Action Scheduler and its recovery hooks must be initialized.' );
}
$locales = array(
	'en_US' => 'Invoice', 'fa_IR' => 'فاکتور', 'tr_TR' => 'Fatura', 'ar' => 'فاتورة',
	'fr_FR' => 'Facture', 'de_DE' => 'Rechnung', 'ru_RU' => 'Счёт', 'es_ES' => 'Factura',
	'pt_PT' => 'Fatura', 'pt_BR' => 'Fatura', 'hy' => 'Հաշիվ', 'hi_IN' => 'इनवॉइस',
	'zh_CN' => '发票', 'ja' => '請求書',
);
$jobs = new \WCInvoicePrinter\PrintJob\PrintJobRepository();
$job = $jobs->create( array(
	'order_id' => 999999999, 'trigger_type' => 'automatic', 'idempotency_key' => wp_generate_uuid4(),
	'template_id' => 'classic', 'provider_id' => 'browser', 'printer_id' => '', 'copies' => 1,
) );
$temporary_jobs = array( $job['id'] );
// Remove the isolated history fixture even if an assertion stops this CLI command.
register_shutdown_function( static function () use ( &$temporary_jobs ): void {
	global $wpdb;
	foreach ( $temporary_jobs as $id ) {
		$wpdb->delete( $wpdb->prefix . 'wc_invoice_print_jobs', array( 'id' => $id ), array( '%d' ) );
	}
} );
wp_set_current_user( 1 );
foreach ( $locales as $locale => $label ) {
	$switched = switch_to_locale( $locale );
	try {
		if ( get_locale() !== $locale || __( 'Invoice', 'wc-invoice-printer' ) !== $label ) {
			throw new RuntimeException( 'The bundled catalog did not load for ' . $locale );
		}
		$rtl = in_array( $locale, array( 'fa_IR', 'ar' ), true );
		if ( is_rtl() !== $rtl ) {
			throw new RuntimeException( 'WordPress direction is incorrect for ' . $locale );
		}
		$templates = new \WCInvoicePrinter\Template\TemplateRegistry();
		$invoice = ( new \WCInvoicePrinter\Invoice\InvoiceFactory( new \WCInvoicePrinter\Settings\SettingsRepository() ) )->sample( is_rtl() );
		$renderer = new \WCInvoicePrinter\Template\HtmlRenderer( $templates );
		foreach ( array( 'classic', 'compact', 'thermal' ) as $id ) {
			$html = $renderer->render( $invoice, $id );
			$title = 'compact' === $id ? $label : __( 'INVOICE', 'wc-invoice-printer' );
			if ( false === strpos( $html, $title ) || false === strpos( $html, 'lang="' . str_replace( '_', '-', $locale ) . '"' ) || false === strpos( $html, 'dir="' . ( $rtl ? 'rtl' : 'ltr' ) . '"' ) ) {
				throw new RuntimeException( 'The invoice was not localized: ' . $locale . '/' . $id );
			}
			$pdf = ( new \WCInvoicePrinter\Pdf\MpdfRenderer() )->render( $html, $templates->get( $id ) );
			if ( 0 !== strpos( $pdf, '%PDF-' ) ) {
				throw new RuntimeException( 'PDF generation failed: ' . $locale . '/' . $id );
			}
		}
		foreach ( array( 0, 1, 2, 3, 5, 11, 21, 100 ) as $count ) {
			$text = _n( 'Print %d invoice', 'Print %d invoices', $count, 'wc-invoice-printer' );
			if ( false === strpos( $text, '%d' ) ) {
				throw new RuntimeException( 'Plural formatting failed for ' . $locale );
			}
		}
		$admin = new \WCInvoicePrinter\Admin\AdminPage( new \WCInvoicePrinter\Settings\SettingsRepository(), $templates, $jobs );
		foreach ( array( 'general', 'templates', 'automatic', 'printers', 'jobs' ) as $section ) {
			$_GET = array( 'tab' => $section );
			ob_start();
			try { $admin->render(); } finally { $screen = ob_get_clean(); }
			if ( false === strpos( $screen, esc_html__( 'Invoice Printer', 'wc-invoice-printer' ) ) ) {
				throw new RuntimeException( 'Admin screen not translated: ' . $locale . '/' . $section );
			}
			if ( 'jobs' === $section ) {
				foreach ( array( 'Classic', 'Automatic', 'Queued' ) as $message ) {
					if ( false === strpos( $screen, esc_html__( $message, 'wc-invoice-printer' ) ) ) {
						throw new RuntimeException( 'Job label not translated: ' . $locale . '/' . $message );
					}
				}
			}
			if ( 'printers' === $section ) {
				foreach ( array( 'Local printer (no API key)', 'Open local test page', 'PrintNode (optional automatic printing)' ) as $message ) {
					if ( false === strpos( $screen, esc_html__( $message, 'wc-invoice-printer' ) ) || ( 'en_US' !== $locale && $message === __( $message, 'wc-invoice-printer' ) ) ) {
						throw new RuntimeException( 'Local printer screen not translated: ' . $locale . '/' . $message );
					}
				}
			}
		}
		WP_CLI::log( $locale . ': catalog, direction, plurals, 5 admin screens, HTML and PDFs OK' );
	} finally {
		if ( $switched ) { restore_previous_locale(); }
	}
}
$site_locale = get_option( 'WPLANG', '' );
$order = null;
$switched = false;
$block_mail = static function (): bool { return true; };
add_filter( 'pre_wp_mail', $block_mail, 100 );
try {
	update_option( 'WPLANG', 'ja' );
	$order = wc_create_order();
	$order->set_status( 'processing' );
	$order->save();
	$queued = $jobs->create( array(
		'order_id' => $order->get_id(), 'trigger_type' => 'manual', 'idempotency_key' => wp_generate_uuid4(),
		'template_id' => 'classic', 'provider_id' => 'language-test', 'printer_id' => '1', 'copies' => 1,
	) );
	$temporary_jobs[] = $queued['id'];
	$pdf = new class() implements \WCInvoicePrinter\Pdf\PdfRendererInterface {
		public string $html = '';
		public function render( string $html, \WCInvoicePrinter\Template\TemplateDefinition $template ): string {
			$this->html = $html;
			return '%PDF-test';
		}
	};
	$provider = new class() implements \WCInvoicePrinter\Printing\PrintProviderInterface {
		public string $title = '';
		public function id(): string { return 'language-test'; }
		public function test_connection(): array { return array(); }
		public function printers( bool $force_refresh = false ): array { return array(); }
		public function submit( string $pdf_bytes, string $printer_id, int $copies, string $title ): \WCInvoicePrinter\Printing\SubmissionResult {
			$this->title = $title;
			return new \WCInvoicePrinter\Printing\SubmissionResult( 'language-test-job' );
		}
	};
	$providers = new \WCInvoicePrinter\Printing\PrintProviderRegistry();
	$providers->register( $provider );
	$templates = new \WCInvoicePrinter\Template\TemplateRegistry();
	$worker = new \WCInvoicePrinter\Automation\PrintWorker(
		$jobs, new \WCInvoicePrinter\Invoice\InvoiceFactory( new \WCInvoicePrinter\Settings\SettingsRepository() ),
		new \WCInvoicePrinter\Template\HtmlRenderer( $templates ), $pdf, $templates, $providers,
		new \WCInvoicePrinter\Printing\RetryPolicy(), new \WCInvoicePrinter\Automation\Scheduler( $jobs )
	);
	$switched = switch_to_locale( 'fa_IR' );
	if ( 'fa_IR' !== determine_locale() ) { throw new RuntimeException( 'Could not establish the operator locale.' ); }
	$worker->process( $queued['id'] );
	if ( 'submitted' !== $jobs->find( $queued['id'] )['status'] || false === strpos( $pdf->html, 'lang="ja" dir="ltr"' ) || '請求書 ' . $order->get_order_number() !== $provider->title ) {
		throw new RuntimeException( 'The queued invoice did not use the store language.' );
	}
	if ( 'fa_IR' !== determine_locale() || 'فاکتور' !== __( 'Invoice', 'wc-invoice-printer' ) ) {
		throw new RuntimeException( 'The worker did not restore the operator language: ' . determine_locale() . ' / ' . __( 'Invoice', 'wc-invoice-printer' ) );
	}
	WP_CLI::log( 'Queued invoice uses the Japanese store language and restores the Persian operator language; provider mocked.' );
} finally {
	if ( $order instanceof WC_Order ) { $order->delete( true ); }
	update_option( 'WPLANG', $site_locale );
	if ( $switched ) { restore_previous_locale(); }
	remove_filter( 'pre_wp_mail', $block_mail, 100 );
}
WP_CLI::success( 'All 14 locale catalogs and 42 invoice layouts passed.' );
