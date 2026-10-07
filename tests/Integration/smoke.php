<?php

/**
 * Opt-in smoke test: WCIP_RUN_INTEGRATION_TESTS=1 wp eval-file /path/to/tests/Integration/smoke.php
 * Run ONLY on a disposable WordPress site with WooCommerce and this plugin active.
 * Uses real order CRUD/database/queue/PDF/REST, and intercepts all outbound HTTP.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'WCIP_RUN_INTEGRATION_TESTS' ) ) {
	throw new RuntimeException( 'Run this test with WP-CLI on a disposable site and explicitly set WCIP_RUN_INTEGRATION_TESTS=1.' );
}
if ( ! class_exists( \WCInvoicePrinter\Plugin::class ) || ! function_exists( 'wc_create_order' ) || ! class_exists( 'ActionScheduler' ) || ! \ActionScheduler::is_initialized() ) {
	throw new RuntimeException( 'Activate WooCommerce and WooCommerce Invoice Printer before running this smoke test.' );
}

global $wpdb;
$settings_before = get_option( 'wcip_settings', null );
$cursor_before   = get_option( 'wcip_recovery_cursor', null );
$user_before     = get_current_user_id();
$orders          = array();
$product         = null;
$submissions     = 0;
$http_status     = 201;
$assertions      = 0;
$check           = static function ( bool $condition, string $message ) use ( &$assertions ): void {
	++$assertions;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};
$http_mock = static function ( $preempt, array $args, string $url ) use ( &$submissions, &$http_status ) {
	if ( 'https://api.printnode.com/printjobs' === $url ) {
		++$submissions;
		$payload = json_decode( $args['body'], true );
		if ( 0 !== strpos( base64_decode( $payload['content'], true ) ?: '', '%PDF-' ) ) {
			throw new RuntimeException( 'The provider did not receive a real PDF.' );
		}
		return array( 'headers' => array(), 'response' => array( 'code' => $http_status, 'message' => 'Mocked' ), 'body' => 201 === $http_status ? '9001' : '{}' );
	}
	// Prevent mail/service/update requests from contacting external systems during the test.
	return new WP_Error( 'wcip_test_http_blocked', 'Outbound HTTP is disabled for this integration test.' );
};
add_filter( 'pre_http_request', $http_mock, PHP_INT_MAX, 3 );
$mail_mock = static fn(): bool => true;
add_filter( 'pre_wp_mail', $mail_mock, PHP_INT_MAX );

try {
	$settings = new \WCInvoicePrinter\Settings\SettingsRepository();
	$settings->update( array( 'automatic_enabled' => true, 'automatic_template' => 'classic', 'automatic_copies' => 2, 'printnode_api_key' => 'integration-test-dummy-key', 'printnode_printer_id' => '123', 'logo_url' => '' ) );
	$repository = new \WCInvoicePrinter\PrintJob\PrintJobRepository();
	$scheduler  = new \WCInvoicePrinter\Automation\Scheduler( $repository );
	$templates  = new \WCInvoicePrinter\Template\TemplateRegistry();
	$service    = new \WCInvoicePrinter\PrintJob\PrintJobService( $repository, $scheduler, $settings, $templates );
	$factory    = new \WCInvoicePrinter\Invoice\InvoiceFactory( $settings );
	$html       = new \WCInvoicePrinter\Template\HtmlRenderer( $templates );
	$pdf        = new \WCInvoicePrinter\Pdf\MpdfRenderer();

	$product = new WC_Product_Simple();
	$product->set_name( 'Integration notebook دفتر' );
	$product->set_regular_price( '50' );
	$product->save();
	$order = wc_create_order();
	$check( $order instanceof WC_Order, 'WooCommerce must create a real test order.' );
	$orders[] = $order;
	$item = new WC_Order_Item_Product();
	$item->set_product( $product );
	$item->set_quantity( 2 );
	$item->set_subtotal( '100' );
	$item->set_total( '80' );
	$item->set_taxes( array( 'subtotal' => array( 1 => '10' ), 'total' => array( 1 => '8' ) ) );
	$item->add_meta_data( '_private_test', 'DO_NOT_SHOW', true );
	$item->add_meta_data( 'Color', 'Blue', true );
	$order->add_item( $item );
	$order->set_billing_first_name( 'Integration' );
	$order->set_billing_last_name( 'Customer' );
	$order->calculate_totals( false );
	$order->save();
	$check( null === $service->create_automatic( $order ), 'Unpaid order must not create an automatic job.' );
	$order->payment_complete( 'integration-tx-one' );
	$job = $repository->latest_for_order( $order->get_id(), 'automatic' );
	$check( is_array( $job ) && 'queued' === $job['status'], 'Payment should persist a queued automatic job.' );
	$check( (int) $job['action_id'] > 0 && as_has_scheduled_action( \WCInvoicePrinter\Automation\Scheduler::HOOK, array( 'job_id' => (int) $job['id'] ), \WCInvoicePrinter\Automation\Scheduler::GROUP ), 'Real Action Scheduler action must exist.' );
	$order->set_transaction_id( 'integration-tx-two' );
	$order->save();
	do_action( 'woocommerce_payment_complete', $order->get_id() );
	$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_invoice_print_jobs WHERE order_id = %d AND trigger_type = %s", $order->get_id(), 'automatic' ) );
	$check( 1 === $count, 'Repeated payment/changed transaction ID must not create a second automatic job.' );
	$invoice = $factory->from_order( $order );
	$price_text = static fn( string $value ): string => html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$check( $price_text( wc_price( 20, array( 'currency' => $order->get_currency() ) ) ) === $price_text( $invoice->items[0]['discount'] ), 'Stored line discount must be reflected on invoice.' );
	$check( $price_text( wc_price( 88, array( 'currency' => $order->get_currency() ) ) ) === $price_text( $invoice->items[0]['total'] ), 'Discounted line total must include stored tax.' );
	$check( false === strpos( $invoice->items[0]['variation'], 'DO_NOT_SHOW' ), 'Private item metadata must not appear.' );
	foreach ( array( 'classic', 'compact', 'thermal' ) as $template_id ) {
		$bytes = $pdf->render( $html->render( $invoice->with_rtl( true ), $template_id ), $templates->get( $template_id ) );
		$check( 0 === strpos( $bytes, '%PDF-' ), 'Every bundled template must render a real RTL PDF.' );
	}
	ActionScheduler::runner()->process_action( (int) $job['action_id'], 'WCIP integration' );
	$check( 'submitted' === $repository->find( (int) $job['id'] )['status'], 'Worker should persist submitted status.' );
	$check( ActionScheduler_Store::STATUS_COMPLETE === ActionScheduler::store()->get_status( (int) $job['action_id'] ), 'Real Action Scheduler runner must complete the action.' );
	do_action( \WCInvoicePrinter\Automation\Scheduler::HOOK, (int) $job['id'] );
	$check( 1 === $submissions, 'Duplicate worker invocation must not send a second PDF.' );
	$check( ! $repository->cancel( (int) $job['id'] ), 'An accepted job must not be cancelled.' );

	$http_status = 503;
	$uncertain = $service->create_manual( $order, 'compact', 'printnode', '123', 1 );
	ActionScheduler::runner()->process_action( (int) $uncertain['action_id'], 'WCIP integration' );
	$check( 'unknown' === $repository->find( (int) $uncertain['id'] )['status'], 'Submission HTTP 503 must be unknown.' );
	$check( ! $repository->retry_failed( (int) $uncertain['id'] ), 'Unknown jobs must not become safe retries.' );

	$http_status = 429;
	$limited = $service->create_manual( $order, 'classic', 'printnode', '123', 1 );
	ActionScheduler::runner()->process_action( (int) $limited['action_id'], 'WCIP integration' );
	$limited = $repository->find( (int) $limited['id'] );
	$check( 'queued' === $limited['status'] && 1 === (int) $limited['attempt_count'], 'Definite rate-limit rejection must requeue once.' );
	$check( as_next_scheduled_action( \WCInvoicePrinter\Automation\Scheduler::HOOK, array( 'job_id' => (int) $limited['id'] ), \WCInvoicePrinter\Automation\Scheduler::GROUP ) >= time() + 58, 'Retry scheduled from a running action must preserve the delay.' );
	ActionScheduler::runner()->process_action( (int) $limited['action_id'], 'WCIP integration' );
	$limited = $repository->find( (int) $limited['id'] );
	ActionScheduler::runner()->process_action( (int) $limited['action_id'], 'WCIP integration' );
	$limited = $repository->find( (int) $limited['id'] );
	$check( 'failed' === $limited['status'] && 3 === (int) $limited['attempt_count'], 'Real queue retries must stop at three attempts.' );
	$check( ! $repository->retry_failed( (int) $limited['id'] ), 'Exhausted rate-limit job must not be retried.' );

	$repair = $service->create_manual( $order, 'classic', 'printnode', '123', 1 );
	as_unschedule_all_actions( \WCInvoicePrinter\Automation\Scheduler::HOOK, array( 'job_id' => (int) $repair['id'] ), \WCInvoicePrinter\Automation\Scheduler::GROUP );
	update_option( 'wcip_recovery_cursor', 0, false );
	$scheduler->recover();
	$check( as_has_scheduled_action( \WCInvoicePrinter\Automation\Scheduler::HOOK, array( 'job_id' => (int) $repair['id'] ), \WCInvoicePrinter\Automation\Scheduler::GROUP ), 'Recovery must reschedule an orphaned queued row with a stale action ID.' );
	$check( $repository->cancel( (int) $repair['id'] ), 'Queued jobs must be cancellable.' );
	$history = $repository->list( array( 'order_id' => $order->get_id(), 'status' => 'submitted' ) );
	$check( 1 === $history['total'] && 1 === count( $history['items'] ), 'Real database history filters/count must match.' );
	$resume = $service->create_manual( $order, 'classic', 'printnode', '123', 1 );
	\WCInvoicePrinter\Infrastructure\Activator::deactivate();
	$check( 'queued' === $repository->find( (int) $resume['id'] )['status'] && null === $repository->find( (int) $resume['id'] )['action_id'], 'Deactivation must preserve a queued row and release its action reference.' );
	update_option( 'wcip_recovery_cursor', 0, false );
	$scheduler->recover();
	$check( as_has_scheduled_action( \WCInvoicePrinter\Automation\Scheduler::HOOK, array( 'job_id' => (int) $resume['id'] ), \WCInvoicePrinter\Automation\Scheduler::GROUP ), 'Retained queue must recover after deactivation.' );

	wp_set_current_user( 0 );
	$request = new WP_REST_Request( 'POST', '/wc-invoice-printer/v1/print' );
	$request->set_body_params( array( 'order_ids' => array( $order->get_id() ), 'template_id' => 'classic', 'provider_id' => 'printnode', 'printer_id' => '123', 'copies' => 1 ) );
	$response = rest_do_request( $request );
	$check( 401 === $response->get_status(), 'Unauthenticated REST printing must be denied.' );
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	$check( ! empty( $admins ), 'Disposable site needs an administrator for authenticated REST tests.' );
	wp_set_current_user( $admins[0]->ID );
	$request->set_param( 'order_ids', array( $order->get_id(), PHP_INT_MAX ) );
	$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_invoice_print_jobs" );
	$response = rest_do_request( $request );
	$check( 404 === $response->get_status(), 'REST must reject a bulk request containing a missing order.' );
	$check( $before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_invoice_print_jobs" ), 'Invalid bulk selection must create no partial jobs.' );
	$request->set_param( 'order_ids', array( $order->get_id() ) );
	$response = rest_do_request( $request );
	$check( 201 === $response->get_status() && 1 === $response->get_data()['queued'], 'Authenticated valid REST request must queue a job.' );
	WP_CLI::log( 'PASS: ' . $assertions . ' integration assertions; storage=' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS' : 'legacy' ) . '; all HTTP mocked.' );
} finally {
	try {
		foreach ( $orders as $order ) {
			$job_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wc_invoice_print_jobs WHERE order_id = %d", $order->get_id() ) );
			foreach ( $job_ids as $id ) { as_unschedule_all_actions( \WCInvoicePrinter\Automation\Scheduler::HOOK, array( 'job_id' => (int) $id ), \WCInvoicePrinter\Automation\Scheduler::GROUP ); }
			$wpdb->delete( $wpdb->prefix . 'wc_invoice_print_jobs', array( 'order_id' => $order->get_id() ), array( '%d' ) );
			$order->delete( true );
		}
	} finally {
		try {
			if ( $product ) { $product->delete( true ); }
		} finally {
			null === $settings_before ? delete_option( 'wcip_settings' ) : update_option( 'wcip_settings', $settings_before, false );
			null === $cursor_before ? delete_option( 'wcip_recovery_cursor' ) : update_option( 'wcip_recovery_cursor', $cursor_before, false );
			wp_set_current_user( $user_before );
		}
	}
	// Keep transport mocks through shutdown, when WooCommerce can dispatch email
	// and Action Scheduler can attempt asynchronous loopback requests.
}
