<?php

namespace WCInvoicePrinter\Infrastructure;

/** Retire the former cloud configuration without redirecting old print jobs. */
final class PrintingMigration {
	public static function run(): bool {
		if ( '1.3.0' === get_option( 'wcip_printing_migration_version' ) ) { return true; }
		global $wpdb;
		$stored = get_option( 'wcip_settings', array() );
		if ( is_array( $stored ) ) {
			$legacy = false;
			foreach ( array( 'printnode_api_key', 'printnode_printer_id', 'printnode_printer_name' ) as $key ) {
				if ( array_key_exists( $key, $stored ) ) { $legacy = true; }
				unset( $stored[ $key ] );
			}
			if ( $legacy ) { $stored['automatic_enabled'] = false; update_option( 'wcip_settings', $stored, false ); }
		}
		// Leave submitted/confirmed history unchanged. Never replay an old
		// cloud job through a new CUPS queue, including a stale scheduled action.
		$table = $wpdb->prefix . 'wc_invoice_print_jobs';
		$result = $wpdb->query( "UPDATE {$table} SET status = 'cancelled', action_id = NULL, error_code = 'provider_retired', error_message = 'The former cloud printing provider has been removed. Review output and create an intentional CUPS print if needed.' WHERE provider_id = 'printnode' AND status = 'queued'" );
		if ( false === $result ) { return false; }
		$result = $wpdb->query( "UPDATE {$table} SET status = 'unknown', error_code = 'provider_retired', error_message = 'The former provider was removed while this job was processing. Check paper output before reprinting.' WHERE provider_id = 'printnode' AND status = 'processing'" );
		if ( false === $result ) { return false; }
		$prefix = $wpdb->esc_like( '_transient_wcip_printnode_printers' ) . '%';
		$timeout = $wpdb->esc_like( '_transient_timeout_wcip_printnode_printers' ) . '%';
		if ( false === $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $prefix, $timeout ) ) ) { return false; }
		update_option( 'wcip_printing_migration_version', '1.3.0', false ); return true;
	}
}
