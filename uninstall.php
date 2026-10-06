<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'wcip_process_print_job', array(), 'wc-invoice-printer' );
}

foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
	$role = get_role( $role_name );
	if ( $role ) {
		foreach ( array( 'wcip_print_invoices', 'wcip_view_print_jobs', 'wcip_manage_settings' ) as $capability ) {
			$role->remove_cap( $capability );
		}
	}
}

if ( ! get_option( 'wcip_delete_data_on_uninstall', false ) ) {
	return;
}

global $wpdb;
$table = $wpdb->prefix . 'wc_invoice_print_jobs';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted WordPress table prefix.
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
delete_option( 'wcip_settings' );
delete_option( 'wcip_db_version' );
delete_option( 'wcip_capabilities_version' );
delete_option( 'wcip_delete_data_on_uninstall' );
delete_option( 'wcip_recovery_cursor' );
