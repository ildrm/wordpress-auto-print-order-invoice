<?php
/** Run only via wp eval-file on a disposable site. Never uses a live provider. */
if ( '1' !== getenv( 'WCIP_RUN_INTEGRATION_TESTS' ) || ! defined( 'ABSPATH' ) || ! class_exists( 'WooCommerce' ) ) { throw new RuntimeException( 'Opt in on a disposable WordPress/WooCommerce site.' ); }

use WCInvoicePrinter\Automation\PaidOrderReconciler;
use WCInvoicePrinter\Automation\PaymentEligibilityPolicy;
use WCInvoicePrinter\Automation\Scheduler;
use WCInvoicePrinter\Infrastructure\Activator;
use WCInvoicePrinter\Infrastructure\DatabaseLease;
use WCInvoicePrinter\Invoice\InvoiceFactory;
use WCInvoicePrinter\Invoice\DocumentCodeService;
use WCInvoicePrinter\Integration\ExportService;
use WCInvoicePrinter\Fulfillment\FulfillmentService;
use WCInvoicePrinter\PrintJob\PrintJobRepository;
use WCInvoicePrinter\PrintJob\PrintJobService;
use WCInvoicePrinter\PrintJob\PrintConfirmationService;
use WCInvoicePrinter\PrintJob\DocumentPrintState;
use WCInvoicePrinter\Settings\SettingsRepository;
use WCInvoicePrinter\Template\TemplateRegistry;
use WCInvoicePrinter\Template\HtmlRenderer;
use WCInvoicePrinter\Pdf\MpdfRenderer;

$GLOBALS['wcip_remediation_checks'] = array(); $checks =& $GLOBALS['wcip_remediation_checks']; $created = array(); $product = null; $subscriber_id = 0;
$settings_before = get_option( 'wcip_settings' );
$option_keys = array( 'wcip_discovery_since', 'wcip_reconciliation_state', 'wcip_reconciliation_stats' );
$options_before = array(); foreach ( $option_keys as $key ) { $options_before[ $key ] = get_option( $key ); }
function wcip_check( bool $condition, string $label ): void { $checks =& $GLOBALS['wcip_remediation_checks']; if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); } $checks[] = $label; echo 'PASS: ' . $label . "\n"; }
function wcip_request( string $route, array $body, string $method = 'POST' ) { $request = new WP_REST_Request( $method, '/wc-invoice-printer/v1' . $route ); $request->set_body_params( $body ); return rest_do_request( $request ); }
function wcip_reset_throttle(): void { global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->prefix}wcip_runtime_leases WHERE lease_name LIKE 'api\\_%'" ); }

// Fake only the explicitly configured receiver/CUPS; no real dispatch.
$receiver_response = array( 'response' => array( 'code' => 202 ), 'body' => '{"accepted":true,"receipt":"fixture-receipt"}', 'headers' => array() );
$receiver_calls = 0;
$http_fake = static function ( $pre, $args, $url ) use ( &$receiver_response, &$receiver_calls ) {
	if ( false !== strpos( $url, 'cups.example.test' ) ) { return new WP_Error( 'fixture_blocked', 'Live CUPS disabled for integration testing.' ); }
	if ( 'https://example.com/wcip-receiver' === $url ) { ++$receiver_calls; return $receiver_response; }
	return $pre;
};
add_filter( 'pre_http_request', $http_fake, 10, 3 );
$no_mail = static function () { return true; }; add_filter( 'pre_wp_mail', $no_mail );

try {
	global $wpdb;
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]; wp_set_current_user( $admin->ID );
	$settings = new SettingsRepository(); $settings->update( array( 'automatic_enabled' => false, 'barcode_enabled' => false, 'cups_endpoint' => 'https://cups.example.test', 'cups_printer_id' => '7', 'show_customer_note' => true ) );
	$templates = new TemplateRegistry(); $jobs = new PrintJobRepository(); $scheduler = new Scheduler( $jobs ); $service = new PrintJobService( $jobs, $scheduler, $settings, $templates );
	wcip_check( Activator::maybe_upgrade(), 'T29 migration is rerunnable' );
	foreach ( array( 'wcip_runtime_leases', 'wcip_document_references', 'wcip_fulfillment', 'wcip_fulfillment_events', 'wcip_export_outbox' ) as $table ) { wcip_check( $wpdb->prefix . $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $table ) ) ), 'Operational table ' . $table ); }
	$lease1 = new DatabaseLease(); $lease2 = new DatabaseLease();
	wcip_check( $lease1->acquire( 'fixture-coordinator' ) && ! $lease2->acquire( 'fixture-coordinator' ), 'T05 exclusive coordinator lease' );
	wcip_check( $lease1->renew( 'fixture-coordinator' ), 'Lease same-second heartbeat remains valid' );
	$lease1->release( 'fixture-coordinator' ); wcip_check( $lease2->acquire( 'fixture-coordinator' ), 'Lease release permits new coordinator' ); $lease2->release( 'fixture-coordinator' );
	$product = new WC_Product_Simple(); $product->set_name( 'Long fixture product — محصول — 商品' ); $product->set_sku( 'WCIP-FIXTURE-' . wp_generate_uuid4() ); $product->set_regular_price( '12.50' ); $product->set_weight( '1.25' ); $product->set_virtual( false ); $product->save();
	$order = wc_create_order(); $created[] = $order->get_id();
	$order->add_product( $product, 2 ); $shipping = new WC_Order_Item_Shipping(); $shipping->set_method_id( 'flat_rate' ); $shipping->set_method_title( 'Fixture shipping' ); $order->add_item( $shipping ); $order->set_address( array( 'first_name' => 'Buyer', 'last_name' => 'Fixture', 'address_1' => '10 Buyer Street', 'city' => 'London', 'country' => 'GB', 'postcode' => 'SW1A 1AA', 'phone' => '+44 20 1111' ), 'billing' );
	$order->set_address( array( 'first_name' => 'Recipient', 'last_name' => 'Fixture', 'address_1' => '20 Recipient Street', 'city' => 'London', 'country' => 'GB', 'postcode' => 'SW1A 2AA', 'phone' => '+44 20 2222' ), 'shipping' );
	$order->set_date_created( time() - 5 * DAY_IN_SECONDS ); $order->set_date_paid( time() ); $order->set_payment_method( 'fixture-online' ); $order->set_payment_method_title( 'Fixture gateway' ); $order->set_status( 'processing' ); $order->set_customer_note( '<script>bad()</script>Leave at reception.' ); $order->update_meta_data( '_fixture_unit', '<b>Unit 12 & B</b>' ); $order->calculate_totals(); $order->save();
	$settings->update( array( 'shipping_field_mapping' => '{"unit":"_fixture_unit"}' ) );
	$data = ( new InvoiceFactory( $settings ) )->from_order( $order );
	wcip_check( '+44 20 2222' === $data->customer['shipping_phone'] && false !== strpos( $data->customer['shipping_address'], 'Recipient' ), 'T18 shipping contact precedes billing' );
	wcip_check( 'Unit 12 & B' === $data->customer['recipient']['extra']['unit'], 'T19 trusted field mapping sanitizes HTML' );
	$offline = wc_create_order(); $created[] = $offline->get_id(); $offline->set_payment_method( 'cod' ); $offline->set_status( 'processing' ); $offline->set_date_paid( time() ); $offline->save();
	wcip_check( ! ( new PaymentEligibilityPolicy( $settings ) )->confirmed( $offline ), 'T07 native COD processing is not collected money' );
	$pending = wc_create_order(); $created[] = $pending->get_id(); $pending->set_status( 'pending' ); $pending->save();
	wcip_check( 'ineligible' === ( new DocumentPrintState( $jobs, $settings ) )->for_order( $pending )['state'], 'T06 unpaid column is ineligible' );
	$settings->update( array( 'automatic_enabled' => true ) );
	update_option( 'wcip_discovery_since', time() - 120, false ); delete_option( 'wcip_reconciliation_state' );
	$historical = wc_create_order(); $created[] = $historical->get_id(); $historical->set_payment_method( 'fixture-online' ); $historical->set_date_paid( time() - DAY_IN_SECONDS ); $historical->set_status( 'processing' ); $historical->save();
	( new \WCInvoicePrinter\Automation\AutomaticPrintHandler( $service ) )->payment_complete( $historical->get_id() );
	wcip_check( null === $jobs->latest_for_order( $historical->get_id(), 'automatic' ), 'T09 replayed historical payment event cannot bypass cutoff' );
	wcip_check( null === $jobs->latest_for_order( $order->get_id(), 'automatic' ), 'T03 deliberately missed payment hook has no job' );
	$reconciler = new PaidOrderReconciler( $settings, $service, $jobs );
	$preview = $reconciler->preview( time() - 120, time() + 1 );
	wcip_check( null === $jobs->latest_for_order( $order->get_id(), 'automatic' ) && in_array( $order->get_id(), array_column( $preview['items'], 'order_id' ), true ), 'T10 dry-run finds old-created newly paid order without printing' );
	$reconciler->run(); $auto = $jobs->latest_for_order( $order->get_id(), 'automatic' );
	wcip_check( is_array( $auto ), 'T02 T03 paid-date discovery creates missed job' );
	$again = $service->create_automatic( $order ); $order->set_transaction_id( 'changed-fixture-transaction' ); $order->save(); $reconciler->run();
	wcip_check( null === $jobs->latest_for_order( $historical->get_id(), 'automatic' ), 'T09 recently modified historical payment cannot bypass cutoff' );
	wcip_check( $auto['id'] === $again['id'] && $auto['id'] === $service->create_automatic( $order )['id'], 'T04 changed transaction and repeated reconciliation converge' );
	wcip_check( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_invoice_print_jobs WHERE order_id = %d AND trigger_type = 'automatic'", $order->get_id() ) ), 'T04 one automatic logical job in real database' );
	$jobs->cancel( (int) $auto['id'] );
	wcip_check( $auto['id'] === $service->create_automatic( $order )['id'], 'Cancelled automatic job remains present rather than reprinted' );
	$label = $service->create_manual( $order, 'shipping-label', 'browser', '', 1 ); $jobs->browser_ready( (int) $label['id'] );
	$order->set_date_modified( time() - 120 ); $order->save();
	$before = wc_get_order( $order->get_id() )->get_date_modified()->getTimestamp();
	( new PrintConfirmationService( $jobs ) )->confirm( (int) $label['id'] );
	wcip_check( null === $jobs->latest_confirmed_for_order( $order->get_id() ) && null !== $jobs->latest_confirmed_for_order( $order->get_id(), 'shipping_label' ), 'T17 label confirmation does not confirm invoice' );
	wcip_check( $before === wc_get_order( $order->get_id() )->get_date_modified()->getTimestamp(), 'T04 print confirmation leaves order date and sorting unchanged' );
	$settings->update( array( 'fulfillment_on_confirmation' => true ) );
	$invoice = $service->create_manual( $order, 'classic', 'browser', '', 1 ); $jobs->browser_ready( (int) $invoice['id'] );
	$confirmation = new PrintConfirmationService( $jobs ); $confirmation->confirm( (int) $invoice['id'] ); $confirmation->confirm( (int) $invoice['id'] );
	$stage = ( new FulfillmentService( $settings ) )->get( $order->get_id() );
	wcip_check( 'preparing' === $stage['stage'] && 1 === (int) $stage['revision'], 'T15 repeated physical confirmation has one fulfillment effect' );
	wcip_check( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wcip_fulfillment_events WHERE event_key = %s", 'confirmation:' . $invoice['id'] ) ), 'T15 durable unique confirmation event' );
	$reprint = $service->create_manual( $order, 'classic', 'browser', '', 1 ); $jobs->fail( (int) $reprint['id'], 'failed', 'generation_failed', 'Fixture rejection', 'queued' );
	wcip_check( 'printed' === ( new DocumentPrintState( $jobs, $settings ) )->for_order( $order )['state'], 'T16 failed reprint preserves previous printed evidence' );
	$filtered = wc_get_orders( array( 'wcip_print_state' => 'printed', 'return' => 'ids', 'limit' => 50 ) );
	wcip_check( in_array( $order->get_id(), $filtered, true ) && ! in_array( $pending->get_id(), $filtered, true ), 'T07 T28 native confirmed filter matches invoice history' );
	$unprinted = wc_get_orders( array( 'wcip_print_state' => 'not_printed', 'return' => 'ids', 'limit' => 50 ) );
	wcip_check( ! in_array( $offline->get_id(), $unprinted, true ) && ! in_array( $order->get_id(), $unprinted, true ), 'T07 native unprinted filter excludes offline and recorded jobs' );
	$jobs->prime_states( array( $order->get_id(), $pending->get_id() ) );
	wcip_check( null !== $jobs->latest_confirmed_for_order( $order->get_id() ) && null === $jobs->latest_confirmed_for_order( $pending->get_id() ), 'T30 visible page batch preserves confirmed and missing states' );
	$settings->update( array( 'barcode_enabled' => true, 'barcode_type' => 'QR' ) );
	$data = ( new InvoiceFactory( $settings ) )->from_order( $order );
	$html = new HtmlRenderer( $templates ); $pdf = new MpdfRenderer();
	foreach ( array( 'classic', 'classic-a5', 'compact', 'thermal', 'thermal58', 'shipping-label', 'packing-list' ) as $id ) {
		$document = $html->render( $data, $id );
		wcip_check( false === strpos( $document, '<script>bad' ), 'T26 escaped note in ' . $id );
		$bytes = $pdf->render( $document, $templates->get( $id ) ); wcip_check( 0 === strpos( $bytes, '%PDF-' ), 'T21 valid PDF ' . $id );
		if ( getenv( 'WCIP_RENDER_OUTPUT' ) ) { file_put_contents( rtrim( getenv( 'WCIP_RENDER_OUTPUT' ), '/' ) . '/' . $id . '.pdf', $bytes ); }
		if ( 'shipping-label' === $id || 'packing-list' === $id ) { wcip_check( false === strpos( $document, 'woocommerce-Price-amount' ), 'T17 warehouse document omits prices ' . $id ); }
	}
	$dated = wc_get_order( $order->get_id() ); $settings->update( array( 'document_timezone' => 'America/New_York' ) );
	$dated->set_date_paid( strtotime( '2026-03-08 06:30:00 UTC' ) ); $before_dst = ( new InvoiceFactory( $settings ) )->from_order( $dated )->order['paid_date'];
	$dated->set_date_paid( strtotime( '2026-03-08 07:30:00 UTC' ) ); $after_dst = ( new InvoiceFactory( $settings ) )->from_order( $dated )->order['paid_date'];
	wcip_check( false !== strpos( $before_dst, '1:30' ) && false !== strpos( $after_dst, '3:30' ), 'T31 explicit IANA timezone honors DST without changing saved order time' );
	$settings->update( array( 'document_timezone' => 'site' ) );
	$reference = $data->fulfillment['document_codes']['invoice']['reference'];
	wcip_check( 1 === preg_match( '/^W1-[A-Za-z0-9_-]{16}$/D', $reference ) && false === strpos( $reference, (string) $order->get_id() . ':' ), 'T24 opaque scan reference contains no PII' );
	wp_set_current_user( 0 ); $denied = wcip_request( '/scan', array( 'reference' => $reference ) ); wcip_check( 401 === $denied->get_status(), 'T25 unauthenticated scan denied' );
	$subscriber_id = wp_insert_user( array( 'user_login' => 'wcip-fixture-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) );
	if ( is_wp_error( $subscriber_id ) ) { throw new RuntimeException( 'Subscriber fixture failed.' ); }
	wp_set_current_user( $subscriber_id );
	foreach ( array( '/scan' => array( 'reference' => $reference ), '/reconciliation/preview' => array( 'from' => time() - 120, 'to' => time() ), '/exports' => array( 'order_id' => $order->get_id(), 'event_key' => 'fixture-denied' ) ) as $route => $body ) {
		wcip_check( 403 === wcip_request( $route, $body )->get_status(), 'T25 subscriber denied ' . $route );
	}
	wp_set_current_user( $admin->ID ); wcip_reset_throttle(); $scan = wcip_request( '/scan', array( 'reference' => $reference ) );
	wcip_check( 200 === $scan->get_status() && $scan->get_data()['data']['order_id'] === $order->get_id() && ! isset( $scan->get_data()['data']['recipient'] ), 'T24 T25 authorized scan returns minimal data' );
	$burst = wcip_request( '/scan', array( 'reference' => $reference ) ); wcip_check( 429 === $burst->get_status(), 'T25 scanner burst limit' );
	wcip_reset_throttle(); $invalid = wcip_request( '/scan', array( 'reference' => array( 'malicious' ) ) );
	wcip_check( 400 === $invalid->get_status(), 'T25 REST rejects nonscalar reference schema' );
	wcip_reset_throttle(); $denied_filter = static function () { return false; }; add_filter( 'wcip_can_access_order', $denied_filter );
	$forbidden = wcip_request( '/scan', array( 'reference' => $reference ) ); remove_filter( 'wcip_can_access_order', $denied_filter );
	wcip_check( 403 === $forbidden->get_status(), 'T25 object access restriction denies a valid reference' );
	wcip_reset_throttle(); $bad_backfill = wcip_request( '/reconciliation/enqueue', array( 'order_ids' => array( $order->get_id() ), 'preview_token' => 'never-issued', 'confirm' => true ) );
	wcip_check( 409 === $bad_backfill->get_status(), 'T10 unissued preview token cannot enqueue historical orders' );
	$settings->update( array( 'export_enabled' => true, 'export_endpoint' => 'https://example.com/wcip-receiver', 'export_allowed_host' => 'example.com', 'export_signing_secret' => str_repeat( 'f', 32 ), 'export_include_recipient' => false ) );
	$exports = new ExportService( $settings );
	$intent = $exports->enqueue( $order, 'fixture-manual-export' ); $duplicate = $exports->enqueue( $order, 'fixture-manual-export' );
	wcip_check( $intent['id'] === $duplicate['id'] && 0 === $receiver_calls, 'T27 immutable outbox deduplicates before network' );
	$payload = json_decode( $intent['payload'], true ); wcip_check( ! isset( $payload['recipient'] ) && 2.5 === $payload['net_item_weight_kg'], 'T23 T36 export excludes recipient and labels weight source' );
	$exports->process( (int) $intent['id'] ); $exports->process( (int) $intent['id'] );
	wcip_check( 'accepted' === $exports->find( (int) $intent['id'] )['status'] && 1 === $receiver_calls, 'T27 one generic receiver acceptance under repeated worker' );
	$receiver_response = new WP_Error( 'fixture_timeout', 'Ambiguous delivery' );
	$unknown = $exports->enqueue( $order, 'fixture-timeout-export' ); $exports->process( (int) $unknown['id'] ); $exports->process( (int) $unknown['id'] );
	wcip_check( 'unknown' === $exports->find( (int) $unknown['id'] )['status'] && 2 === $receiver_calls, 'T12 T27 unknown exports are never blindly retried' );
	$disabled = $exports->enqueue( $order, 'fixture-disabled-export' );
	$settings->update( array( 'export_enabled' => false ) ); $calls = $receiver_calls; $exports->process( (int) $disabled['id'] );
	wcip_check( $calls === $receiver_calls && 'failed' === $exports->find( (int) $disabled['id'] )['status'], 'T36 disabled export has no outbound side effect' );
	echo 'PASS: ' . count( $checks ) . ' real WordPress/WooCommerce checks; HPOS=' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off' ) . "\n";
} finally {
	remove_filter( 'pre_http_request', $http_fake, 10 ); remove_filter( 'pre_wp_mail', $no_mail );
	global $wpdb;
	foreach ( $created as $id ) {
		foreach ( array( 'wc_invoice_print_jobs', 'wcip_document_references', 'wcip_fulfillment', 'wcip_fulfillment_events', 'wcip_export_outbox' ) as $suffix ) { $wpdb->delete( $wpdb->prefix . $suffix, array( 'order_id' => $id ), array( '%d' ) ); }
		$order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); }
	}
	if ( $product ) { $product->delete( true ); }
	if ( $subscriber_id && ! is_wp_error( $subscriber_id ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $subscriber_id ); }
	update_option( 'wcip_settings', $settings_before, false );
	foreach ( $options_before as $key => $value ) { if ( false === $value ) { delete_option( $key ); } else { update_option( $key, $value, false ); } }
	foreach ( array( Scheduler::HOOK, ExportService::HOOK, PaidOrderReconciler::HOOK, 'wcip_recover_exports' ) as $hook ) { as_unschedule_all_actions( $hook, array(), Scheduler::GROUP ); }
	wcip_reset_throttle();
}
