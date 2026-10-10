<?php

namespace WCInvoicePrinter\Infrastructure;

final class Activator {
	public const DB_VERSION = '1.4.0';
	public const CAPABILITIES_VERSION = '1.4.0';

	public static function activate(): void {
		if ( ! self::install_schema() ) {
			wp_die( esc_html__( 'The invoice printer database could not be installed. Check database permissions and try activation again.', 'wc-invoice-printer' ), '', array( 'response' => 500 ) );
		}
		self::add_capabilities();
		if ( ! PrintingMigration::run() ) { wp_die( esc_html__( 'The printing configuration migration failed. Check database permissions.', 'wc-invoice-printer' ) ); }
	}

	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'wcip_process_print_job', array(), 'wc-invoice-printer' );
			as_unschedule_all_actions( 'wcip_reconcile_paid_orders', array(), 'wc-invoice-printer' );
			as_unschedule_all_actions( 'wcip_process_export', array(), 'wc-invoice-printer' );
			as_unschedule_all_actions( 'wcip_recover_exports', array(), 'wc-invoice-printer' );
		}
		global $wpdb;
		// Pending jobs remain reviewable and can be recovered when the plugin is reactivated.
		$wpdb->update( $wpdb->prefix . 'wc_invoice_print_jobs', array( 'action_id' => null ), array( 'status' => 'queued' ), array( '%d' ), array( '%s' ) );
	}

	public static function maybe_upgrade(): bool {
		if ( self::DB_VERSION !== get_option( 'wcip_db_version' ) || ! self::table_exists() ) {
			if ( ! self::install_schema() ) { return false; }
		}
		if ( self::CAPABILITIES_VERSION !== get_option( 'wcip_capabilities_version' ) ) {
			self::add_capabilities();
		}
		return PrintingMigration::run();
	}

	private static function install_schema(): bool {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $wpdb->prefix . 'wc_invoice_print_jobs';
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			trigger_type varchar(20) NOT NULL,
			document_type varchar(32) NOT NULL DEFAULT 'invoice',
			idempotency_key char(64) NOT NULL,
			template_id varchar(64) NOT NULL,
			provider_id varchar(64) NOT NULL,
			printer_id varchar(191) NOT NULL,
			copies smallint(5) unsigned NOT NULL DEFAULT 1,
			status varchar(20) NOT NULL,
			attempt_count smallint(5) unsigned NOT NULL DEFAULT 0,
			action_id bigint(20) unsigned NULL,
			external_job_id varchar(191) NULL,
			error_code varchar(64) NULL,
			error_message varchar(500) NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			started_at datetime NULL,
			completed_at datetime NULL,
			printed_at datetime NULL,
			printed_by bigint(20) unsigned NULL,
			printed_note_id bigint(20) unsigned NULL,
			agent_token char(64) NULL,
			agent_user bigint(20) unsigned NULL,
			agent_phase varchar(16) NULL,
			agent_claimed_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY status_created (status, created_at),
			KEY order_created (order_id, created_at),
			KEY order_printed (order_id, printed_at),
			KEY document_printed (order_id, document_type, printed_at),
			KEY document_created (order_id, document_type, created_at),
			KEY trigger_created (trigger_type, created_at),
			KEY action_id (action_id),
			KEY agent_queue (provider_id, printer_id, status, id)
		) {$charset};";
		try {
			dbDelta( $sql );
			// Save the CREATE/ALTER error before the existence query clears it.
			$error = $wpdb->last_error;
			if ( '' !== $error || ! self::table_exists() ) { return false; }
			if ( 'document_type' !== $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'document_type'" ) ) { return false; }
			foreach ( array( 'agent_token', 'agent_user', 'agent_phase', 'agent_claimed_at' ) as $column ) { if ( $column !== $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE '{$column}'" ) ) { return false; } }
			require_once __DIR__ . '/OperationalSchema.php';
			if ( ! OperationalSchema::install() ) { return false; }
		} catch ( \Throwable $error ) {
			return false;
		}
		update_option( 'wcip_db_version', self::DB_VERSION, false );
		if ( ! get_option( 'wcip_discovery_since' ) ) { update_option( 'wcip_discovery_since', time(), false ); }
		return true;
	}

	private static function table_exists(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_invoice_print_jobs';
		try {
			return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	private static function add_capabilities(): void {
		if ( function_exists( 'add_role' ) ) { add_role( 'wcip_print_agent', __( 'Print agent', 'wc-invoice-printer' ), array( 'read' => true, 'wcip_run_print_agent' => true ) ); }
		$roles = array( 'administrator', 'shop_manager' );
		$complete = true;
		foreach ( $roles as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				foreach ( array( 'wcip_print_invoices', 'wcip_view_print_jobs', 'wcip_scan_orders', 'wcip_manage_fulfillment' ) as $capability ) {
					if ( ! $role->has_cap( $capability ) ) { $role->add_cap( $capability ); }
				}
			} else {
				$complete = false;
			}
		}
		$administrator = get_role( 'administrator' );
		if ( $administrator && ! $administrator->has_cap( 'wcip_manage_settings' ) ) {
			$administrator->add_cap( 'wcip_manage_settings' );
		}
		if ( $administrator && ! $administrator->has_cap( 'wcip_export_orders' ) ) { $administrator->add_cap( 'wcip_export_orders' ); }
		if ( $complete ) {
			update_option( 'wcip_capabilities_version', self::CAPABILITIES_VERSION, false );
		}
	}
}
