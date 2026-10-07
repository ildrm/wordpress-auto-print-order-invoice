<?php
/**
 * Plugin Name: WooCommerce Invoice Printer
 * Description: Secure manual and automatic invoice printing for WooCommerce.
 * Version: 1.1.2
 * Author: Shahin Ilderemi
 * Author URI:  https://ildrm.com
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 9.0
 * WC tested up to: 10.7
 * Text Domain: wc-invoice-printer
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'WCIP_VERSION', '1.1.2' );
define( 'WCIP_FILE', __FILE__ );
define( 'WCIP_PATH', plugin_dir_path( __FILE__ ) );
define( 'WCIP_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( WCIP_PATH . 'vendor/autoload.php' ) ) {
	require WCIP_PATH . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'WCInvoicePrinter\\';
			if ( 0 !== strpos( $class, $prefix ) ) {
				return;
			}
			$file = WCIP_PATH . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	);
}

register_activation_hook( __FILE__, array( WCInvoicePrinter\Infrastructure\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( WCInvoicePrinter\Infrastructure\Activator::class, 'deactivate' ) );

add_action( 'before_woocommerce_init', static function (): void {
	if ( class_exists( Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

// Load translations at init, before Action Scheduler initializes at priority 1.
add_action( 'init', static function (): void {
	WCInvoicePrinter\Plugin::boot();
}, 0 );
