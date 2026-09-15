<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! get_option( 'wcip_delete_data_on_uninstall', false ) ) {
	return;
}

global $wpdb;
$table = $wpdb->prefix . 'wc_invoice_print_jobs';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted WordPress table prefix.
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
delete_option( 'wcip_settings' );
delete_option( 'wcip_db_version' );
delete_option( 'wcip_delete_data_on_uninstall' );
