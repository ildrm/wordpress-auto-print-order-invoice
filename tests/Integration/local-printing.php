<?php
/**
 * Real authenticated HTTP checks; run only on a disposable, loopback-accessible site.
 * WCIP_RUN_INTEGRATION_TESTS=1 wp eval-file tests/Integration/local-printing.php
 * No browser scripts execute and no physical print or PrintNode request is made.
 */
if ( '1' !== getenv( 'WCIP_RUN_INTEGRATION_TESTS' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run this test explicitly on a disposable WordPress site.' );
}
if ( ! class_exists( \WCInvoicePrinter\Plugin::class ) || ! class_exists( 'WooCommerce' ) || defined( 'WCIP_PRINTNODE_API_KEY' ) ) {
	throw new RuntimeException( 'Activate WooCommerce and this plugin without an external PrintNode key.' );
}
global $wpdb;
$settings = get_option( 'wcip_settings', array() );
$cookies = $_COOKIE;
$previous_user = get_current_user_id();
$order = null;
$sessions = WP_Session_Tokens::get_instance( 1 );
$expiration = time() + 600;
$token = $sessions->create( $expiration );
$logged_in = wp_generate_auth_cookie( 1, $expiration, 'logged_in', $token );
$auth = wp_generate_auth_cookie( 1, $expiration, is_ssl() ? 'secure_auth' : 'auth', $token );
$request = static function ( string $url ) use ( $logged_in, $auth ): array {
	$response = wp_remote_get( $url, array(
		'timeout' => 30, 'redirection' => 0,
		'cookies' => array(
			new WP_HTTP_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => $logged_in ) ),
			new WP_HTTP_Cookie( array( 'name' => is_ssl() ? SECURE_AUTH_COOKIE : AUTH_COOKIE, 'value' => $auth ) ),
		),
	) );
	if ( is_wp_error( $response ) ) { throw new RuntimeException( $response->get_error_message() ); }
	return array( wp_remote_retrieve_response_code( $response ), wp_remote_retrieve_body( $response ), wp_remote_retrieve_header( $response, 'location' ) );
};
try {
	wp_set_current_user( 1 );
	$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in;
	// Ignore WooCommerce's first-admin-visit activation redirect on a fresh test site.
	delete_transient( '_wc_activation_redirect' );
	update_option( 'wcip_settings', array( 'default_template' => 'thermal', 'automatic_enabled' => false, 'printnode_api_key' => '' ) );
	$jobs_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_invoice_print_jobs" );
	list( $status, $screen, $location ) = $request( add_query_arg( array( 'page' => 'wc-invoice-printer', 'tab' => 'printers' ), admin_url( 'admin.php' ) ) );
	if ( 200 !== $status || ! preg_match( '/class="button button-primary"[^>]*href="([^"]+)"/', $screen, $match ) ) {
		throw new RuntimeException( 'Local test page is unavailable without an API key. HTTP status: ' . $status . '; location: ' . $location . '; response: ' . substr( wp_strip_all_tags( $screen ), 0, 180 ) );
	}
	$sample_url = html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' );
	parse_str( (string) parse_url( $sample_url, PHP_URL_QUERY ), $args );
	if ( 'wcip_preview' !== ( $args['action'] ?? '' ) || '1' !== ( $args['sample'] ?? '' ) || 'thermal' !== ( $args['template'] ?? '' ) || empty( $args['_wpnonce'] ) ) {
		throw new RuntimeException( 'The local test link does not use the configured template and protected sample route.' );
	}
	if ( false !== strpos( $screen, '<details class="wcip-printnode-setup" open' ) || false === strpos( $screen, 'data-wcip-action="refresh-printers" disabled' ) ) {
		throw new RuntimeException( 'Unconfigured PrintNode must be optional and discovery disabled.' );
	}
	list( $status, $sample ) = $request( $sample_url );
	if ( 200 !== $status || false === strpos( $sample, 'onclick="window.print()"' ) || false === strpos( $sample, 'width:72mm' ) || false !== strpos( $sample, 'addEventListener("load"' ) ) {
		throw new RuntimeException( 'The local sample must render a printable thermal page without automatic printing.' );
	}
	if ( $jobs_before !== (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_invoice_print_jobs" ) ) {
		throw new RuntimeException( 'Opening a local test page must not create print jobs.' );
	}
	update_option( 'wcip_settings', array( 'printnode_api_key' => 'wcip-isolated-fake-credential', 'printnode_printer_id' => '5', 'printnode_printer_name' => 'Office & Kitchen' ) );
	list( $status, $configured ) = $request( add_query_arg( array( 'page' => 'wc-invoice-printer', 'tab' => 'printers' ), admin_url( 'admin.php' ) ) );
	if ( 200 !== $status || false === strpos( $configured, '<details class="wcip-printnode-setup" open' ) || false !== strpos( $configured, 'wcip-isolated-fake-credential' ) || false !== strpos( $configured, 'data-wcip-action="refresh-printers" disabled' ) || false === strpos( $configured, 'wcip-local-printer' ) ) {
		throw new RuntimeException( 'Configured PrintNode must remain usable alongside local printing without exposing the credential.' );
	}
	update_option( 'wcip_settings', array( 'automatic_enabled' => false, 'printnode_api_key' => '' ) );
	list( $status ) = $request( add_query_arg( '_wpnonce', 'invalid', $sample_url ) );
	if ( 403 !== $status ) { throw new RuntimeException( 'The local test route accepted an invalid nonce.' ); }
	$order = wc_create_order();
	$order->set_status( 'pending' );
	$order->save();
	$url = wp_nonce_url( add_query_arg( array(
		'action' => 'wcip_preview', 'order_ids' => $order->get_id(), 'template' => 'classic', 'copies' => '2', 'print' => '1',
	), admin_url( 'admin-post.php' ) ), 'wcip_preview_invoices' );
	list( $status, $invoice ) = $request( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );
	$job = ( new \WCInvoicePrinter\PrintJob\PrintJobRepository() )->latest_for_order( $order->get_id(), 'manual' );
	if ( 200 !== $status || 2 !== substr_count( $invoice, '<section class="wcip-document">' ) || false === strpos( $invoice, 'window.print();' ) || ! $job || 'browser' !== $job['provider_id'] || 'submitted' !== $job['status'] || 2 !== (int) $job['copies'] || ! empty( $job['action_id'] ) ) {
		throw new RuntimeException( 'Local order printing failed without an API key.' );
	}
	WP_CLI::success( 'Local settings, protected thermal sample, no sample jobs, nonce rejection and two-copy browser order printing pass without an API key; no physical output.' );
} finally {
	if ( $order instanceof WC_Order ) {
		$wpdb->delete( $wpdb->prefix . 'wc_invoice_print_jobs', array( 'order_id' => $order->get_id() ), array( '%d' ) );
		$order->delete( true );
	}
	update_option( 'wcip_settings', $settings );
	$sessions->destroy( $token );
	$_COOKIE = $cookies;
	wp_set_current_user( $previous_user );
}
