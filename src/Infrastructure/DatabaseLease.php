<?php

namespace WCInvoicePrinter\Infrastructure;

/** Atomic, expiring site coordinator leases in plugin-owned storage. */
final class DatabaseLease implements LeaseInterface {
	private string $table;
	private string $owner;
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'wcip_runtime_leases';
		$this->owner = bin2hex( random_bytes( 16 ) );
	}
	public function acquire( string $name, int $ttl = 120 ): bool {
		global $wpdb;
		$now = time();
		$sql = $wpdb->prepare( "INSERT INTO {$this->table} (lease_name, owner, expires_at) VALUES (%s, %s, %d) ON DUPLICATE KEY UPDATE owner = IF(expires_at < %d, VALUES(owner), owner), expires_at = IF(expires_at < %d, VALUES(expires_at), expires_at)", $name, $this->owner, $now + $ttl, $now, $now );
		if ( false === $wpdb->query( $sql ) ) { return false; }
		return $this->owner === $wpdb->get_var( $wpdb->prepare( "SELECT owner FROM {$this->table} WHERE lease_name = %s", $name ) );
	}
	public function renew( string $name, int $ttl = 120 ): bool {
		global $wpdb;
		if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET expires_at = %d WHERE lease_name = %s AND owner = %s AND expires_at >= %d", time() + $ttl, $name, $this->owner, time() ) ) ) { return false; }
		return $this->owner === $wpdb->get_var( $wpdb->prepare( "SELECT owner FROM {$this->table} WHERE lease_name = %s AND expires_at >= %d", $name, time() ) );
	}
	public function release( string $name ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table} WHERE lease_name = %s AND owner = %s", $name, $this->owner ) );
	}
}
