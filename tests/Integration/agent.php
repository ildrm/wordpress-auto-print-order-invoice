<?php
/** Disposable-site only. WCIP_RUN_AGENT_TEST=1 WCIP_AGENT_PHASE=prepare|expire|invalidate|verify wp eval-file ... */
if ( '1' !== getenv( 'WCIP_RUN_AGENT_TEST' ) || ! class_exists( 'WooCommerce' ) ) { throw new RuntimeException( 'Opt in on a disposable WooCommerce site.' ); }
add_filter( 'pre_wp_mail', static function () { return true; } );
$phase = getenv( 'WCIP_AGENT_PHASE' );
$settings = new \WCInvoicePrinter\Settings\SettingsRepository();
$jobs = new \WCInvoicePrinter\PrintJob\PrintJobRepository();
$service = new \WCInvoicePrinter\PrintJob\PrintJobService( $jobs, new \WCInvoicePrinter\Automation\Scheduler( $jobs ), $settings, new \WCInvoicePrinter\Template\TemplateRegistry() );
$check = static function ( $value, string $message ): void { if ( ! $value ) { throw new RuntimeException( $message ); } };
if ( 'prepare' === $phase ) {
	$settings->update( array( 'automatic_enabled' => false ) );
	$old = get_option( 'wcip_agent_fixture', array() );
	foreach ( $old['orders'] ?? array() as $order_id ) { global $wpdb; $wpdb->delete( $wpdb->prefix . 'wc_invoice_print_jobs', array( 'order_id' => $order_id ), array( '%d' ) ); $order = wc_get_order( $order_id ); if ( $order ) { $order->delete( true ); } }
	$user = username_exists( 'wcip-local-agent' ) ?: wp_create_user( 'wcip-local-agent', wp_generate_password( 32 ), 'agent@example.test' );
	if ( is_wp_error( $user ) ) { throw new RuntimeException( $user->get_error_message() ); }
	( new WP_User( $user ) )->set_role( 'wcip_print_agent' );
	$app = WP_Application_Passwords::create_new_application_password( $user, array( 'name' => 'Disposable agent check ' . bin2hex( random_bytes( 4 ) ) ) );
	if ( is_wp_error( $app ) ) { throw new RuntimeException( $app->get_error_message() ); }
	$settings->update( array( 'agent_user_id' => $user, 'agent_queue' => 'Office', 'automatic_provider' => 'agent', 'automatic_enabled' => false, 'cups_endpoint' => '' ) );
	$ids = array(); $orders = array();
	for ( $i = 0; $i < 4; ++$i ) {
		$settings->update( array( 'automatic_enabled' => false ) );
		$order = wc_create_order(); $order->set_status( 'processing' ); $order->set_date_paid( time() ); $order->set_billing_first_name( 'Disposable' ); $order->set_billing_last_name( 'Agent fixture' ); $order->save();
		if ( 1 === $i ) { $settings->update( array( 'automatic_enabled' => true ) ); }
		$job = 1 === $i ? $service->create_automatic( $order ) : $service->create_manual( $order, 'classic', 'agent', 'Office', 2 );
		$check( 'queued' === $job['status'] && null === $job['action_id'], 'Pull job does not need Action Scheduler.' );
		$orders[] = $order->get_id(); $ids[] = (int) $job['id'];
	}
	$settings->update( array( 'automatic_enabled' => true ) );
	update_option( 'wcip_agent_fixture', array( 'jobs' => $ids, 'orders' => $orders, 'user' => $user ), false );
	echo wp_json_encode( array( 'username' => 'wcip-local-agent', 'password' => $app[0], 'user' => $user, 'jobs' => $ids ) );
} elseif ( 'expire' === $phase ) {
	$f = get_option( 'wcip_agent_fixture' ); global $wpdb;
	$wpdb->update( $wpdb->prefix . 'wc_invoice_print_jobs', array( 'agent_claimed_at' => gmdate( 'Y-m-d H:i:s', time() - 601 ) ), array( 'id' => $f['jobs'][0] ) );
	echo 'Prepared lease expired.';
} elseif ( 'invalidate' === $phase ) {
	$f = get_option( 'wcip_agent_fixture' ); $order = wc_get_order( $f['orders'][1] ); $order->set_status( 'cancelled' ); $order->save(); echo 'Automatic order cancelled before print authorization.';
} elseif ( 'verify' === $phase ) {
	$f = get_option( 'wcip_agent_fixture' );
	foreach ( $f['jobs'] as $index => $id ) {
		$job = $jobs->find( $id );
		$expected = array( 'submitted', 'failed', 'submitted', 'unknown' )[ $index ];
		$check( $expected === $job['status'], 'Wrong outcome for job ' . $index );
		$check( empty( $job['printed_at'] ), 'Spooler acceptance never confirms physical paper.' );
	}
	$check( '1.4.0' === get_option( 'wcip_db_version' ), 'Agent schema migration missing.' );
	$check( ! user_can( $f['user'], 'wcip_manage_settings' ) && ! user_can( $f['user'], 'wcip_print_invoices' ) && user_can( $f['user'], 'wcip_run_print_agent' ), 'Agent account privileges are scoped.' );
	echo 'PASS: real agent queue outcomes, no paper confirmation, schema and scoped role.';
} elseif ( 'queue_safety' === $phase ) {
	global $wpdb;
	$f = get_option( 'wcip_agent_fixture' );
	$queue = new \WCInvoicePrinter\Printing\Agent\AgentQueue();
	$job = $service->create_manual( wc_get_order( $f['orders'][0] ), 'classic', 'agent', 'Office', 1 );
	$id = (int) $job['id']; $user = (int) $f['user']; $token = bin2hex( random_bytes( 32 ) );
	try {
		$check( ! $queue->candidates( 'office' ), 'Logical routes must be case-sensitive in MariaDB.' );
		$check( ! $queue->claim( $id, 'office', $user, $token ), 'Wrong-case claim must fail.' );
		$check( $queue->claim( $id, 'Office', $user, $token ), 'Claim failed.' );
		$wpdb->update( $wpdb->prefix . 'wc_invoice_print_jobs', array( 'agent_claimed_at' => gmdate( 'Y-m-d H:i:s', time() - 601 ) ), array( 'id' => $id ) );
		$check( ! $queue->start( $id, $user, $token ), 'Expired preparation must not start.' );
		$queue->recover( 'Office' );
		$next = bin2hex( random_bytes( 32 ) );
		$check( $queue->claim( $id, 'Office', $user, $next ), 'Expired preparation should be reclaimable.' );
		$check( ! $queue->reject_prepared( $id, $user, $token ), 'Stale rejection must preserve new ownership.' );
		$check( $queue->start( $id, $user, $next ), 'New claim should start once.' );
		$wpdb->update( $wpdb->prefix . 'wc_invoice_print_jobs', array( 'agent_claimed_at' => gmdate( 'Y-m-d H:i:s', time() - 601 ) ), array( 'id' => $id ) );
		$queue->recover( 'Office' );
		$check( 'unknown' === $jobs->find( $id )['status'] && ! $queue->candidates( 'Office' ), 'Expired started work must never requeue.' );
		$check( ! $queue->receipt( $id, $user + 1, $next, 'submitted' ), 'Another user cannot resolve uncertain work.' );
		$check( $queue->receipt( $id, $user, $next, 'submitted' ), 'The valid late receipt should resolve its own claim.' );
		$check( 'submitted' === $jobs->find( $id )['status'] && empty( $jobs->find( $id )['printed_at'] ), 'Late acceptance does not confirm paper.' );
		echo 'PASS: 11 real MariaDB lease, case, ownership, stale-token and late-receipt checks.';
	} finally { $wpdb->delete( $wpdb->prefix . 'wc_invoice_print_jobs', array( 'id' => $id ), array( '%d' ) ); }
} else { throw new RuntimeException( 'Unknown phase.' ); }
