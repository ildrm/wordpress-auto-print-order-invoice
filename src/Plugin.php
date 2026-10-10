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
use WCInvoicePrinter\Printing\Cups\CupsProvider;
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
		if ( ! Activator::maybe_upgrade() ) {
			add_action( 'admin_notices', array( self::class, 'database_notice' ) );
			return;
		}
		$settings  = new SettingsRepository();
		$templates = new TemplateRegistry();
		$invoices  = new InvoiceFactory( $settings );
		$html      = new HtmlRenderer( $templates );
		$pdf       = new MpdfRenderer();
		$jobs      = new PrintJobRepository();
		$scheduler = new Scheduler( $jobs );
		$providers = new PrintProviderRegistry();
		$providers->register( new CupsProvider( $settings ) );
		$service   = new PrintJobService( $jobs, $scheduler, $settings, $templates );
		$worker    = new PrintWorker( $jobs, $invoices, $html, $pdf, $templates, $providers, new RetryPolicy(), $scheduler );
		$automatic = new AutomaticPrintHandler( $service );
		$reconciler = new \WCInvoicePrinter\Automation\PaidOrderReconciler( $settings, $service, $jobs );
		$operations = new \WCInvoicePrinter\Rest\OperationsController( $settings, $service, $jobs );
		$console = new \WCInvoicePrinter\Admin\OperationsConsole( $settings );
		$exports = new \WCInvoicePrinter\Integration\ExportService( $settings );
		$fulfillment = new \WCInvoicePrinter\Fulfillment\FulfillmentService( $settings );
		$rest      = new RestController( $settings, $providers, $service, $jobs, $scheduler, $invoices, $html, $pdf, $templates );
		$agent = new \WCInvoicePrinter\Rest\AgentController( $settings, $jobs, $invoices, $html, $pdf, $templates, $reconciler );
		add_action( 'rest_api_init', array( $agent, 'register' ) );
		$admin     = new AdminPage( $settings, $templates, $jobs );
		$orders    = new OrderIntegration( $settings, $templates, $jobs );
		$print_filters = new \WCInvoicePrinter\Admin\OrderPrintFilters();
		add_filter( 'woocommerce_order_query', static function ( $results, array $args ) use ( $jobs ) {
			if ( ! empty( $args['wcip_prime_print_states'] ) ) { $orders = is_object( $results ) ? ( $results->orders ?? array() ) : $results; $ids = array(); foreach ( $orders as $order ) { $ids[] = $order instanceof \WC_Order ? $order->get_id() : absint( $order ); } $jobs->prime_states( $ids ); } return $results;
		}, 10, 2 );
		add_filter( 'the_posts', static function ( array $posts, $query ) use ( $jobs ): array { if ( is_admin() && $query->is_main_query() && 'shop_order' === $query->get( 'post_type' ) ) { $jobs->prime_states( array_column( $posts, 'ID' ) ); } return $posts; }, 10, 2 );
		$preview   = new PreviewController( $invoices, $html, $templates, $service, $jobs );

		add_action( 'rest_api_init', array( $operations, 'register' ) );
		add_action( 'wcip_admin_operations_screen', array( $console, 'render' ) );
		add_action( 'admin_post_wcip_save_operations', array( $console, 'save' ) );
		add_action( 'wcip_print_confirmed', array( $fulfillment, 'on_confirmation' ) );
		add_action( \WCInvoicePrinter\Integration\ExportService::HOOK, array( $exports, 'process' ) );
		add_action( 'wcip_recover_exports', array( $exports, 'recover' ) );
		add_action( 'action_scheduler_init', array( $exports, 'initialize' ) );
		add_action( 'init', array( $exports, 'initialize' ), 20 );
		add_action( 'wcip_fulfillment_changed', array( $exports, 'on_fulfillment' ), 10, 2 );
		add_action( \WCInvoicePrinter\Automation\PaidOrderReconciler::HOOK, array( $reconciler, 'run' ) );
		add_action( 'action_scheduler_init', array( $reconciler, 'schedule' ) );
		add_action( 'init', array( $reconciler, 'schedule' ), 20 );
		add_action( 'woocommerce_payment_complete', array( $automatic, 'payment_complete' ) );
		add_action( 'woocommerce_order_status_changed', array( $automatic, 'paid_status_fallback' ), 10, 4 );
		add_action( Scheduler::HOOK, array( $worker, 'process' ) );
		add_action( 'action_scheduler_init', array( $scheduler, 'recover' ) );
		add_action( 'action_scheduler_failed_action', array( $worker, 'interrupted' ) );
		add_action( 'action_scheduler_failed_execution', array( $worker, 'interrupted' ) );
		add_action( 'action_scheduler_unexpected_shutdown', array( $worker, 'interrupted' ) );
		add_action( 'rest_api_init', array( $rest, 'register' ) );
		add_action( 'admin_menu', array( $admin, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_assets' ) );
		add_action( 'admin_post_wcip_save_settings', array( $admin, 'save' ) );
		add_action( 'admin_post_wcip_preview', array( $preview, 'output' ) );
		add_action( 'restrict_manage_posts', array( $print_filters, 'control' ), 10, 2 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $print_filters, 'control' ), 10, 2 );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $print_filters, 'hpos_args' ) );
		add_filter( 'woocommerce_order_query_args', array( $print_filters, 'query_args' ) );
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $print_filters, 'cpt_args' ), 10, 2 );
		add_action( 'pre_get_posts', array( $print_filters, 'legacy_query' ) );
		add_filter( 'posts_where', array( $print_filters, 'legacy_where' ), 10, 2 );
		add_filter( 'woocommerce_orders_table_query_clauses', array( $print_filters, 'hpos_clauses' ), 10, 3 );
		add_action( 'add_meta_boxes', array( $orders, 'add_meta_boxes' ) );
		add_filter( 'manage_edit-shop_order_columns', array( $orders, 'columns' ) );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $orders, 'columns' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $orders, 'render_column' ), 10, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $orders, 'render_column' ), 10, 2 );
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
		if ( current_user_can( 'activate_plugins' ) ) { echo '<div class="notice notice-warning"><p>' . esc_html__( 'WooCommerce Invoice Printer needs its Composer dependencies for PDF and automatic printing. Browser printing remains available.', 'wc-invoice-printer' ) . '</p></div>'; }
	}

	public static function database_notice(): void {
		if ( current_user_can( 'activate_plugins' ) ) { echo '<div class="notice notice-error"><p>' . esc_html__( 'WooCommerce Invoice Printer could not prepare its database. Printing is unavailable; check database permissions and reactivate the plugin.', 'wc-invoice-printer' ) . '</p></div>'; }
	}
}
