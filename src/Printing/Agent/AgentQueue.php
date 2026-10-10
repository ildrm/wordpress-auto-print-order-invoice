<?php

namespace WCInvoicePrinter\Printing\Agent;

/** Atomic delivery handshake. A downloaded PDF never authorizes printing by itself. */
final class AgentQueue {
	public const LEASE_SECONDS = 600;
	private string $table;
	public function __construct() { global $wpdb; $this->table = $wpdb->prefix . 'wc_invoice_print_jobs'; }
	public function candidates( string $route ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE provider_id = 'agent' AND BINARY printer_id = %s AND status = 'queued' ORDER BY id ASC LIMIT 10", $route ), ARRAY_A ) ?: array();
	}
	public function claim( int $id, string $route, int $user, string $token ): bool {
		global $wpdb; $now = current_time( 'mysql', true );
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET status = 'processing', agent_phase = 'prepared', agent_token = %s, agent_user = %d, agent_claimed_at = %s, started_at = %s, updated_at = %s, attempt_count = attempt_count + 1 WHERE id = %d AND provider_id = 'agent' AND BINARY printer_id = %s AND status = 'queued'", hash( 'sha256', $token ), $user, $now, $now, $now, $id, $route ) );
	}
	public function start( int $id, int $user, string $token ): bool {
		global $wpdb;
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET agent_phase = 'started', agent_claimed_at = %s, updated_at = %s WHERE id = %d AND provider_id = 'agent' AND status = 'processing' AND agent_phase = 'prepared' AND agent_token = %s AND agent_user = %d AND agent_claimed_at >= %s", current_time( 'mysql', true ), current_time( 'mysql', true ), $id, hash( 'sha256', $token ), $user, gmdate( 'Y-m-d H:i:s', time() - self::LEASE_SECONDS ) ) );
	}
	public function receipt( int $id, int $user, string $token, string $outcome ): bool {
		global $wpdb;
		if ( ! in_array( $outcome, array( 'submitted', 'unknown', 'failed' ), true ) ) { return false; }
		$now = current_time( 'mysql', true );
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET status = %s, agent_phase = 'done', external_job_id = %s, completed_at = %s, updated_at = %s, error_code = %s, error_message = %s WHERE id = %d AND provider_id = 'agent' AND status IN ('processing', 'unknown') AND agent_phase = 'started' AND agent_token = %s AND agent_user = %d", $outcome, 'agent:' . $id, $now, $now, 'submitted' === $outcome ? '' : 'agent_' . $outcome, 'submitted' === $outcome ? '' : 'The local print agent reported an unsuccessful or uncertain result. Inspect the local spooler and paper before reprinting.', $id, hash( 'sha256', $token ), $user ) );
	}
	public function reject_prepared( int $id, int $user, string $token ): bool {
		global $wpdb;
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET status = 'failed', agent_phase = 'done', error_code = 'agent_preparation_failed', error_message = 'Review order eligibility and PDF configuration before reprinting.', completed_at = %s, updated_at = %s WHERE id = %d AND provider_id = 'agent' AND status = 'processing' AND agent_phase = 'prepared' AND agent_token = %s AND agent_user = %d", current_time( 'mysql', true ), current_time( 'mysql', true ), $id, hash( 'sha256', $token ), $user ) );
	}
	public function recover( string $route ): void {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::LEASE_SECONDS );
		// A prepared lease cannot print without an unexpired, successful start handshake.
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET status = 'queued', agent_phase = NULL, agent_token = NULL, agent_user = NULL, agent_claimed_at = NULL, started_at = NULL, updated_at = %s WHERE provider_id = 'agent' AND BINARY printer_id = %s AND status = 'processing' AND agent_phase = 'prepared' AND agent_claimed_at < %s", current_time( 'mysql', true ), $route, $cutoff ) );
		// A started lease may already have produced paper; never replay it automatically.
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET status = 'unknown', error_code = 'agent_interrupted', error_message = 'Inspect the local spooler and paper before reprinting.', updated_at = %s WHERE provider_id = 'agent' AND BINARY printer_id = %s AND status = 'processing' AND agent_phase = 'started' AND agent_claimed_at < %s", current_time( 'mysql', true ), $route, $cutoff ) );
	}
}
