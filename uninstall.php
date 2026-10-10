<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( 'wcip_process_print_job', 'wcip_reconcile_paid_orders', 'wcip_process_export', 'wcip_recover_exports' ) as $hook ) { as_unschedule_all_actions( $hook, array(), 'wc-invoice-printer' ); }
}

foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
	$role = get_role( $role_name );
	if ( $role ) {
		foreach ( array( 'wcip_print_invoices', 'wcip_view_print_jobs', 'wcip_manage_settings', 'wcip_scan_orders', 'wcip_manage_fulfillment', 'wcip_export_orders' ) as $capability ) {
			$role->remove_cap( $capability );
		}
	}
}

if ( ! get_option( 'wcip_delete_data_on_uninstall', false ) ) {
	return;
}

if ( function_exists( 'remove_role' ) ) { remove_role( 'wcip_print_agent' ); }
delete_option( 'wcip_agent_last_seen' );
delete_transient( 'wcip_agent_reconcile' );

global $wpdb;
$table = $wpdb->prefix . 'wc_invoice_print_jobs';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted WordPress table prefix.
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
foreach ( array( 'wcip_runtime_leases', 'wcip_document_references', 'wcip_fulfillment', 'wcip_fulfillment_events', 'wcip_export_outbox' ) as $suffix ) { $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$suffix}" ); }
foreach ( array( 'wcip_discovery_since', 'wcip_reconciliation_state', 'wcip_reconciliation_stats', 'wcip_export_cursor' ) as $option ) { delete_option( $option ); }
delete_option( 'wcip_settings' );
delete_option( 'wcip_db_version' );
delete_option( 'wcip_capabilities_version' );
delete_option( 'wcip_delete_data_on_uninstall' );
delete_option( 'wcip_recovery_cursor' );
