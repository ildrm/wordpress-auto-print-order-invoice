<?php

namespace WCInvoicePrinter\PrintJob;

final class PrintJobRepository {
	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'wc_invoice_print_jobs';
	}

	public function create( array $data ): array {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$this->table,
			array(
				'order_id'       => absint( $data['order_id'] ),
				'trigger_type'    => sanitize_key( $data['trigger_type'] ),
				'idempotency_key' => sanitize_text_field( $data['idempotency_key'] ),
				'template_id'     => sanitize_key( $data['template_id'] ),
				'provider_id'     => sanitize_key( $data['provider_id'] ),
				'printer_id'      => sanitize_text_field( $data['printer_id'] ),
				'copies'          => max( 1, min( 20, absint( $data['copies'] ) ) ),
				'status'          => JobStatus::QUEUED->value,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		return $this->find_by_key( $data['idempotency_key'] ) ?? array();
	}

	public function find( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted table name.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function find_by_key( string $key ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted table name.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE idempotency_key = %s", $key ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function latest_for_order( int $order_id, ?string $trigger = null ): ?array {
		global $wpdb;
		if ( $trigger ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted table name.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE order_id = %d AND trigger_type = %s ORDER BY created_at DESC LIMIT 1", $order_id, $trigger ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted table name.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE order_id = %d ORDER BY created_at DESC LIMIT 1", $order_id ), ARRAY_A );
		}
		return is_array( $row ) ? $row : null;
	}

	public function claim( int $id ): bool {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted table name.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET status = %s, attempt_count = attempt_count + 1, started_at = %s, updated_at = %s WHERE id = %d AND status = %s", JobStatus::PROCESSING->value, $now, $now, $id, JobStatus::QUEUED->value ) );
		return 1 === $updated;
	}

	public function set_action_id( int $id, int $action_id ): void {
		global $wpdb;
		$wpdb->update( $this->table, array( 'action_id' => $action_id, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ), array( '%d', '%s' ), array( '%d' ) );
	}

	public function submitted( int $id, string $external_id ): void {
		$this->set_status( $id, JobStatus::SUBMITTED, array( 'external_job_id' => sanitize_text_field( $external_id ), 'completed_at' => current_time( 'mysql', true ), 'error_code' => null, 'error_message' => null ) );
	}

	public function browser_ready( int $id ): void {
		$this->set_status( $id, JobStatus::SUBMITTED, array( 'external_job_id' => null, 'completed_at' => current_time( 'mysql', true ) ) );
	}

	public function fail( int $id, JobStatus $status, string $code, string $message ): void {
		$this->set_status( $id, $status, array( 'error_code' => sanitize_key( $code ), 'error_message' => $this->sanitize_error( $message ), 'completed_at' => current_time( 'mysql', true ) ) );
	}

	public function requeue( int $id, string $code, string $message ): void {
		$this->set_status( $id, JobStatus::QUEUED, array( 'error_code' => sanitize_key( $code ), 'error_message' => $this->sanitize_error( $message ) ) );
	}

	public function cancel( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted table name.
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET status = %s, updated_at = %s, completed_at = %s WHERE id = %d AND status = %s", JobStatus::CANCELLED->value, current_time( 'mysql', true ), current_time( 'mysql', true ), $id, JobStatus::QUEUED->value ) );
	}

	public function retry_failed( int $id ): bool {
		global $wpdb;
		// Only definitive provider rejection codes are safe to retry automatically.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted table name.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET status = %s, action_id = NULL, completed_at = NULL, updated_at = %s WHERE id = %d AND status = %s AND attempt_count < %d AND (error_code = %s OR error_code LIKE %s)", JobStatus::QUEUED->value, current_time( 'mysql', true ), $id, JobStatus::FAILED->value, 3, 'http_429', 'http_5%' ) );
		return 1 === $updated;
	}

	public function list( array $filters, int $page = 1, int $per_page = 20 ): array {
		global $wpdb;
		$where  = array( '1=1' );
		$values = array();
		if ( ! empty( $filters['status'] ) && in_array( $filters['status'], array_column( JobStatus::cases(), 'value' ), true ) ) {
			$where[]  = 'status = %s';
			$values[] = $filters['status'];
		}
		if ( ! empty( $filters['trigger'] ) && in_array( $filters['trigger'], array( 'automatic', 'manual' ), true ) ) {
			$where[]  = 'trigger_type = %s';
			$values[] = $filters['trigger'];
		}
		if ( ! empty( $filters['order_id'] ) ) {
			$where[]  = 'order_id = %d';
			$values[] = absint( $filters['order_id'] );
		}
		$offset   = max( 0, ( $page - 1 ) * $per_page );
		$values[] = $per_page;
		$values[] = $offset;
		$sql      = "SELECT SQL_CALC_FOUND_ROWS * FROM {$this->table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC LIMIT %d OFFSET %d';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Conditions are allowlisted and table name is trusted.
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
		$total = (int) $wpdb->get_var( 'SELECT FOUND_ROWS()' );
		return array( 'items' => is_array( $rows ) ? $rows : array(), 'total' => $total );
	}

	private function set_status( int $id, JobStatus $status, array $extra = array() ): void {
		global $wpdb;
		$data = array_merge( array( 'status' => $status->value, 'updated_at' => current_time( 'mysql', true ) ), $extra );
		$wpdb->update( $this->table, $data, array( 'id' => $id ) );
	}

	private function sanitize_error( string $message ): string {
		$message = preg_replace( '/(?:api[_ -]?key|authorization|basic)\s*[:=]?\s*[A-Za-z0-9+\/=._-]+/i', '[credential redacted]', $message );
		return mb_substr( sanitize_text_field( (string) $message ), 0, 500 );
	}
}
