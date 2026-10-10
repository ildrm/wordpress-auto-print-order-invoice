<?php

namespace WCInvoicePrinter\Infrastructure;

final class OperationalSchema {
	public static function install(): bool {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$definitions = array(
			'wcip_runtime_leases' => "lease_name varchar(64) NOT NULL, owner char(32) NOT NULL, expires_at bigint(20) unsigned NOT NULL, PRIMARY KEY  (lease_name), KEY expires_at (expires_at)",
			'wcip_document_references' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL, document_type varchar(32) NOT NULL, package_id varchar(64) NOT NULL DEFAULT '', reference varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				PRIMARY KEY  (id), UNIQUE KEY reference (reference), UNIQUE KEY identity (order_id, document_type, package_id)",
			'wcip_fulfillment' => "order_id bigint(20) unsigned NOT NULL, stage varchar(32) NOT NULL DEFAULT 'not_started', revision bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL, updated_by bigint(20) unsigned NOT NULL DEFAULT 0, last_event varchar(100) NOT NULL, PRIMARY KEY  (order_id)",
			'wcip_fulfillment_events' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT, event_key varchar(100) NOT NULL, order_id bigint(20) unsigned NOT NULL,
				from_stage varchar(32) NOT NULL, to_stage varchar(32) NOT NULL, created_at datetime NOT NULL, actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id), UNIQUE KEY event_key (event_key), KEY order_created (order_id, created_at)",
			'wcip_export_outbox' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT, order_id bigint(20) unsigned NOT NULL, idempotency_key char(64) NOT NULL,
				payload longtext NOT NULL, configuration_hash char(64) NOT NULL, status varchar(20) NOT NULL DEFAULT 'queued', attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				receipt varchar(191) NULL, error_code varchar(64) NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL,
				PRIMARY KEY  (id), UNIQUE KEY idempotency_key (idempotency_key), KEY status_updated (status, updated_at), KEY order_created (order_id, created_at)",
		);
		foreach ( $definitions as $suffix => $columns ) {
			$table = $wpdb->prefix . $suffix;
			$columns = implode( ",\n", array_map( 'trim', preg_split( '/,(?![^()]*\\))/', $columns ) ) );
			dbDelta( "CREATE TABLE {$table} (\n{$columns}\n) ENGINE=InnoDB {$charset};" );
			if ( '' !== $wpdb->last_error || $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { return false; }
			if ( 'wcip_document_references' === $suffix ) {
				$column = $wpdb->get_row( "SHOW FULL COLUMNS FROM {$table} LIKE 'reference'", ARRAY_A );
				if ( ! $column ) { return false; }
				if ( 'ascii_bin' !== $column['Collation'] && false === $wpdb->query( "ALTER TABLE {$table} MODIFY reference varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL" ) ) { return false; }
			}
		}
		return true;
	}
}
