<?php

namespace WCInvoicePrinter;

use WCInvoicePrinter\Admin\AdminPage;
use WCInvoicePrinter\Admin\OrderIntegration;
use WCInvoicePrinter\Admin\PreviewController;
use WCInvoicePrinter\Automation\AutomaticPrintHandler;
use WCInvoicePrinter\Automation\PrintWorker;
use WCInvoicePrinter\Automation\Scheduler;
use WCInvoicePrinter\Infrastructure\Activator;
use WCInvoicePrinter\Invoice\InvoiceFactory;
use WCInvoicePrinter\Pdf\MpdfRenderer;
use WCInvoicePrinter\Printing\PrintNode\PrintNodeProvider;
use WCInvoicePrinter\Printing\PrintProviderRegistry;
use WCInvoicePrinter\Printing\RetryPolicy;
use WCInvoicePrinter\PrintJob\PrintJobRepository;
use WCInvoicePrinter\PrintJob\PrintJobService;
use WCInvoicePrinter\Rest\RestController;
use WCInvoicePrinter\Settings\SettingsRepository;
use WCInvoicePrinter\Template\HtmlRenderer;
use WCInvoicePrinter\Template\TemplateRegistry;

final class Plugin {
	public static function boot(): void {
		load_plugin_textdomain( 'wc-invoice-printer', false, dirname( plugin_basename( WCIP_FILE ) ) . '/languages' );
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( self::class, 'woocommerce_notice' ) );
			return;
		}
		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.0', '<' ) ) {
			add_action( 'admin_notices', array( self::class, 'woocommerce_version_notice' ) );
			return;
		}
		if ( ! class_exists( \Mpdf\Mpdf::class ) ) {
			add_action( 'admin_notices', array( self::class, 'dependency_notice' ) );
		}
		Activator::maybe_upgrade();
		$settings  = new SettingsRepository();
		$templates = new TemplateRegistry();
		$invoices  = new InvoiceFactory( $settings );
		$html      = new HtmlRenderer( $templates );
		$pdf       = new MpdfRenderer();
		$jobs      = new PrintJobRepository();
		$scheduler = new Scheduler( $jobs );
		$providers = new PrintProviderRegistry();
		$providers->register( new PrintNodeProvider( $settings ) );
		$service   = new PrintJobService( $jobs, $scheduler, $settings, $templates );
		$worker    = new PrintWorker( $jobs, $invoices, $html, $pdf, $templates, $providers, new RetryPolicy(), $scheduler );
		$automatic = new AutomaticPrintHandler( $service );
		$rest      = new RestController( $settings, $providers, $service, $jobs, $scheduler, $invoices, $html, $pdf, $templates );
		$admin     = new AdminPage( $settings, $templates, $jobs );
		$orders    = new OrderIntegration( $settings, $templates, $jobs );
		$preview   = new PreviewController( $invoices, $html, $templates, $service, $jobs );

		add_action( 'woocommerce_payment_complete', array( $automatic, 'payment_complete' ) );
		add_action( 'woocommerce_order_status_changed', array( $automatic, 'paid_status_fallback' ), 10, 4 );
		add_action( Scheduler::HOOK, array( $worker, 'process' ) );
		add_action( 'rest_api_init', array( $rest, 'register' ) );
		add_action( 'admin_menu', array( $admin, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_assets' ) );
		add_action( 'admin_post_wcip_save_settings', array( $admin, 'save' ) );
		add_action( 'admin_post_wcip_preview', array( $preview, 'output' ) );
		add_action( 'add_meta_boxes', array( $orders, 'add_meta_boxes' ) );
		add_filter( 'woocommerce_admin_order_actions', array( $orders, 'row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-edit-shop_order', array( $orders, 'bulk_actions' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $orders, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $orders, 'handle_bulk' ), 10, 3 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $orders, 'handle_bulk' ), 10, 3 );
	}

	public static function woocommerce_notice(): void {
		if ( current_user_can( 'activate_plugins' ) ) { echo '<div class="notice notice-error"><p>' . esc_html__( 'WooCommerce Invoice Printer requires WooCommerce to be installed and active.', 'wc-invoice-printer' ) . '</p></div>'; }
	}

	public static function woocommerce_version_notice(): void {
		if ( current_user_can( 'activate_plugins' ) ) { echo '<div class="notice notice-error"><p>' . esc_html__( 'WooCommerce Invoice Printer requires WooCommerce 9.0 or newer.', 'wc-invoice-printer' ) . '</p></div>'; }
	}

	public static function dependency_notice(): void {
		if ( current_user_can( 'activate_plugins' ) ) { echo '<div class="notice notice-warning"><p>' . esc_html__( 'WooCommerce Invoice Printer needs its Composer dependencies for PDF and PrintNode output. Browser printing remains available.', 'wc-invoice-printer' ) . '</p></div>'; }
	}
}
