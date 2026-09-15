<?php

namespace WCInvoicePrinter\Infrastructure;

final class Activator {
	public const DB_VERSION = '1.0.0';

	public static function activate(): void {
		self::install_schema();
		self::add_capabilities();
	}

	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'wcip_process_print_job', array(), 'wc-invoice-printer' );
		}
	}

	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( 'wcip_db_version' ) ) {
			self::install_schema();
		}
	}

	private static function install_schema(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $wpdb->prefix . 'wc_invoice_print_jobs';
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			trigger_type varchar(20) NOT NULL,
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
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY status_created (status, created_at),
			KEY order_created (order_id, created_at),
			KEY trigger_created (trigger_type, created_at),
			KEY action_id (action_id)
		) {$charset};";
		dbDelta( $sql );
		update_option( 'wcip_db_version', self::DB_VERSION, false );
	}

	private static function add_capabilities(): void {
		$roles = array( 'administrator', 'shop_manager' );
		foreach ( $roles as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( 'wcip_print_invoices' );
				$role->add_cap( 'wcip_view_print_jobs' );
			}
		}
		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			$administrator->add_cap( 'wcip_manage_settings' );
		}
	}
}
