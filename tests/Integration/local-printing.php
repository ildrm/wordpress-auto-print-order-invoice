<?php
/**
 * Real authenticated HTTP checks; run only on a disposable, loopback-accessible site.
 * WCIP_RUN_INTEGRATION_TESTS=1 wp eval-file tests/Integration/local-printing.php
 * No browser scripts execute and no physical print or CUPS request is made.
 */
if ( '1' !== getenv( 'WCIP_RUN_INTEGRATION_TESTS' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run this test explicitly on a disposable WordPress site.' );
}
if ( ! class_exists( \WCInvoicePrinter\Plugin::class ) || ! class_exists( 'WooCommerce' ) || defined( 'WCIP_CUPS_PASSWORD' ) ) {
	throw new RuntimeException( 'Activate WooCommerce and this plugin without an external CUPS password.' );
}
global $wpdb;
$settings = get_option( 'wcip_settings', array() );
$cookies = $_COOKIE;
$previous_user = get_current_user_id();
add_filter( 'pre_wp_mail', '__return_true' );
$order = null;
$other_orders = array();
$sessions = WP_Session_Tokens::get_instance( 1 );
$expiration = time() + 600;
$token = $sessions->create( $expiration );
$logged_in = wp_generate_auth_cookie( 1, $expiration, 'logged_in', $token );
$auth = wp_generate_auth_cookie( 1, $expiration, is_ssl() ? 'secure_auth' : 'auth', $token );
$request = static function ( string $url, array $options = array() ) use ( $logged_in, $auth ): array {
	$response = wp_remote_request( $url, array_merge( array(
		'timeout' => 30, 'redirection' => 0,
		'cookies' => array(
			new WP_HTTP_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => $logged_in ) ),
			new WP_HTTP_Cookie( array( 'name' => is_ssl() ? SECURE_AUTH_COOKIE : AUTH_COOKIE, 'value' => $auth ) ),
		),
	), $options ) );
	if ( is_wp_error( $response ) ) { throw new RuntimeException( $response->get_error_message() ); }
	return array( wp_remote_retrieve_response_code( $response ), wp_remote_retrieve_body( $response ), wp_remote_retrieve_header( $response, 'location' ) );
};
try {
	wp_set_current_user( 1 );
	$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in;
	// Ignore WooCommerce's first-admin-visit activation redirect on a fresh test site.
	delete_transient( '_wc_activation_redirect' );
	update_option( 'wcip_settings', array( 'default_template' => 'thermal', 'automatic_enabled' => false, 'cups_endpoint' => '' ) );
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
	if ( false !== strpos( $screen, '<details class="wcip-cups-setup" open' ) || false === strpos( $screen, 'data-wcip-action="refresh-printers" disabled' ) ) {
		throw new RuntimeException( 'Unconfigured CUPS must be optional and discovery disabled.' );
	}
	list( $status, $sample ) = $request( $sample_url );
	if ( 200 !== $status || false === strpos( $sample, 'onclick="window.print()"' ) || false === strpos( $sample, 'width:72mm' ) || false !== strpos( $sample, 'addEventListener("load"' ) ) {
		throw new RuntimeException( 'The local sample must render a printable thermal page without automatic printing.' );
	}
	if ( $jobs_before !== (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_invoice_print_jobs" ) ) {
		throw new RuntimeException( 'Opening a local test page must not create print jobs.' );
	}
	update_option( 'wcip_settings', array( 'cups_endpoint' => 'https://cups.example.test', 'cups_username' => 'fixture', 'cups_password' => 'wcip-isolated-fake-credential', 'cups_printer_id' => 'Office_A4', 'cups_printer_name' => 'Office & Kitchen' ) );
	list( $status, $configured ) = $request( add_query_arg( array( 'page' => 'wc-invoice-printer', 'tab' => 'printers' ), admin_url( 'admin.php' ) ) );
	if ( 200 !== $status || false === strpos( $configured, '<details class="wcip-cups-setup" open' ) || false !== strpos( $configured, 'wcip-isolated-fake-credential' ) || false !== strpos( $configured, 'data-wcip-action="refresh-printers" disabled' ) || false === strpos( $configured, 'wcip-local-printer' ) ) {
		throw new RuntimeException( 'Configured CUPS must remain usable alongside local printing without exposing the credential.' );
	}
	update_option( 'wcip_settings', array( 'automatic_enabled' => false, 'cups_endpoint' => '' ) );
	list( $status ) = $request( add_query_arg( '_wpnonce', 'invalid', $sample_url ) );
	if ( 403 !== $status ) { throw new RuntimeException( 'The local test route accepted an invalid nonce.' ); }
	$order = wc_create_order();
	$order->set_status( 'on-hold' );
	$order->set_date_created( '2024-01-02 10:00:00' );
	$order->set_date_modified( '2024-01-02 11:00:00' );
	$order->save();
	foreach ( array( '01', '03' ) as $day ) {
		$other = wc_create_order();
		$other_orders[] = $other;
		$other->set_status( 'on-hold' );
		$other->set_date_created( '2024-01-' . $day . ' 10:00:00' );
		$other->set_date_modified( '2024-01-' . $day . ' 11:00:00' );
		$other->save();
	}
	$ids = array_merge( array( $order->get_id() ), array_map( static fn( WC_Order $o ): int => $o->get_id(), $other_orders ) );
	$dates = static function () use ( $wpdb, $ids ): array {
		// Read persisted timestamps directly so an object cache cannot hide a change.
		$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$result = array();
		foreach ( $ids as $id ) {
			$sql = $hpos ? "SELECT date_created_gmt AS created, date_updated_gmt AS modified FROM {$wpdb->prefix}wc_orders WHERE id = %d" : "SELECT post_date_gmt AS created, post_modified_gmt AS modified FROM {$wpdb->posts} WHERE ID = %d";
			$result[ $id ] = $wpdb->get_row( $wpdb->prepare( $sql, $id ), ARRAY_A );
		}
		return $result;
	};
	$list_order = static function () use ( $ids ): array {
		$result = array();
		foreach ( array( 'date', 'modified' ) as $by ) {
			$result[ $by ] = wc_get_orders( array( 'include' => $ids, 'limit' => -1, 'orderby' => $by, 'order' => 'DESC', 'return' => 'ids' ) );
		}
		return $result;
	};
	$dates_before = $dates();
	$sort_before = $list_order();
	$url = wp_nonce_url( add_query_arg( array(
		'action' => 'wcip_preview', 'order_ids' => $order->get_id(), 'template' => 'classic', 'copies' => '2', 'print' => '1',
	), admin_url( 'admin-post.php' ) ), 'wcip_preview_invoices' );
	list( $status, $invoice ) = $request( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );
	$job = ( new \WCInvoicePrinter\PrintJob\PrintJobRepository() )->latest_for_order( $order->get_id(), 'manual' );
	if ( 200 !== $status || 2 !== substr_count( $invoice, '<section class="wcip-document">' ) || false === strpos( $invoice, 'window.print();' ) || ! $job || 'browser' !== $job['provider_id'] || 'submitted' !== $job['status'] || 2 !== (int) $job['copies'] || ! empty( $job['action_id'] ) ) {
		throw new RuntimeException( 'Local order printing failed without an API key. HTTP: ' . $status . '; job: ' . wp_json_encode( $job ) . '; body: ' . substr( wp_strip_all_tags( $invoice ), -300 ) );
	}
	if ( ! empty( $job['printed_at'] ) || false === strpos( $invoice, 'data-wcip-confirm' ) || $dates_before !== $dates() || $sort_before !== $list_order() ) {
		throw new RuntimeException( 'Preparing an invoice must preserve order dates/list order and leave printing unconfirmed.' );
	}
	$column = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'manage_woocommerce_page_wc-orders' : 'manage_shop_order_posts';
	$filter = 'manage_shop_order_posts' === $column ? 'manage_edit-shop_order_columns' : $column . '_columns';
	if ( ! isset( apply_filters( $filter, array() )['wcip_printed'] ) ) { throw new RuntimeException( 'The Orders list has no Printed column.' ); }
	ob_start();
	do_action( $column . '_custom_column', 'wcip_printed', 'manage_shop_order_posts' === $column ? $order->get_id() : $order );
	$label = ob_get_clean();
	if ( false === strpos( $label, '>Awaiting confirmation<' ) || false !== strpos( $label, '>Printed<' ) ) { throw new RuntimeException( 'A prepared invoice must await physical confirmation.' ); }
	$endpoint = rest_url( 'wc-invoice-printer/v1/printed' );
	$options = array( 'method' => 'POST', 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( array( 'job_ids' => array( (int) $job['id'] ) ) ) );
	list( $status ) = $request( $endpoint, $options );
	if ( 401 !== $status ) { throw new RuntimeException( 'Confirmation accepted cookie authentication without a REST nonce.' ); }
	$options['headers']['X-WP-Nonce'] = 'invalid';
	list( $status ) = $request( $endpoint, $options );
	if ( 403 !== $status ) { throw new RuntimeException( 'Confirmation accepted an invalid REST nonce.' ); }
	$options['headers']['X-WP-Nonce'] = wp_create_nonce( 'wp_rest' );
	$note_count = static function () use ( $wpdb, $order ): int {
		// CLI's in-memory comment-query cache cannot observe another HTTP process.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID = %d AND comment_type = %s", $order->get_id(), 'order_note' ) );
	};
	$notes_before = $note_count();
	for ( $attempt = 0; $attempt < 2; ++$attempt ) {
		list( $status, $body ) = $request( $endpoint, $options );
		if ( 200 !== $status || true !== ( json_decode( $body, true )['printed'] ?? false ) ) { throw new RuntimeException( 'Authenticated print confirmation failed: ' . $body ); }
	}
	$confirmed = ( new \WCInvoicePrinter\PrintJob\PrintJobRepository() )->find( (int) $job['id'] );
	$note = wc_get_order_note( (int) $confirmed['printed_note_id'] );
	if ( ! $note || $note->customer_note || false === strpos( $note->content, 'Invoice printed.' ) || $notes_before + 1 !== $note_count() ) {
		throw new RuntimeException( 'Confirmation must add exactly one private order note, including after a repeated request.' );
	}
	ob_start();
	do_action( $column . '_custom_column', 'wcip_printed', 'manage_shop_order_posts' === $column ? $order->get_id() : $order );
	$label = ob_get_clean();
	if ( false === strpos( $label, '>Printed<' ) || $dates_before !== $dates() || $sort_before !== $list_order() ) {
		throw new RuntimeException( 'Confirmation must show Printed without changing persisted order dates or either list sort.' );
	}
	WP_CLI::success( 'API-free printing, nonce enforcement, Printed order column, one private note after repeated confirmation, unchanged creation/modification dates and order-list sorting pass; storage=' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS' : 'legacy' ) . '; no physical output.' );
} finally {
	if ( $order instanceof WC_Order ) {
		$wpdb->delete( $wpdb->prefix . 'wc_invoice_print_jobs', array( 'order_id' => $order->get_id() ), array( '%d' ) );
		$order->delete( true );
	}
	foreach ( $other_orders as $other ) { $other->delete( true ); }
	update_option( 'wcip_settings', $settings );
	$sessions->destroy( $token );
	$_COOKIE = $cookies;
	wp_set_current_user( $previous_user );
}
