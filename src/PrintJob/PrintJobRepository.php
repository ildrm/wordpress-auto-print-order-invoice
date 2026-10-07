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
		$key = sanitize_text_field( $data['idempotency_key'] );
		$wpdb->insert(
			$this->table,
			array(
				'order_id'       => absint( $data['order_id'] ),
				'trigger_type'    => sanitize_key( $data['trigger_type'] ),
				'idempotency_key' => $key,
				'template_id'     => sanitize_key( $data['template_id'] ),
				'provider_id'     => sanitize_key( $data['provider_id'] ),
				'printer_id'      => sanitize_text_field( $data['printer_id'] ),
				'copies'          => max( 1, min( 20, absint( $data['copies'] ) ) ),
				'status'          => JobStatus::QUEUED,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		// A concurrent insert may have won the unique-key race. Return that job,
		// but never pretend a database failure created a printable job.
		$job = $this->find_by_key( $key );
		if ( ! $job ) {
			throw new \RuntimeException( __( 'The print job could not be saved.', 'wc-invoice-printer' ) );
		}
		return $job;
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
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE order_id = %d AND trigger_type = %s ORDER BY created_at DESC, id DESC LIMIT 1", $order_id, $trigger ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted table name.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE order_id = %d ORDER BY created_at DESC, id DESC LIMIT 1", $order_id ), ARRAY_A );
		}
		return is_array( $row ) ? $row : null;
	}

	/** Must be called inside the confirmation transaction. */
	public function lock_for_confirmation( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted table name.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d FOR UPDATE", $id ), ARRAY_A );
		if ( ! empty( $wpdb->last_error ) ) { throw new \RuntimeException( 'The print job could not be locked.' ); }
		return is_array( $row ) ? $row : null;
	}

	public function record_confirmation( int $id, int $note_id, int $user_id ): bool {
		global $wpdb;
		// Print tracking lives in this table. Never save the order or alter its dates.
		return 1 === $wpdb->update( $this->table,
			array( 'printed_at' => current_time( 'mysql', true ), 'printed_by' => $user_id, 'printed_note_id' => $note_id ),
			array( 'id' => $id, 'printed_at' => null ), array( '%s', '%d', '%d' ), array( '%d', '%s' )
		);
	}

	public function latest_confirmed_for_order( int $order_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted table name.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE order_id = %d AND printed_at IS NOT NULL ORDER BY printed_at DESC, id DESC LIMIT 1", $order_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function claim( int $id ): bool {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted table name.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET status = %s, attempt_count = attempt_count + 1, started_at = %s, updated_at = %s WHERE id = %d AND status = %s", JobStatus::PROCESSING, $now, $now, $id, JobStatus::QUEUED ) );
		return 1 === $updated;
	}

	public function set_action_id( int $id, int $action_id ): void {
		global $wpdb;
		$data = array( 'action_id' => $action_id, 'updated_at' => current_time( 'mysql', true ) );
		$updated = $wpdb->update( $this->table, $data, array( 'id' => $id, 'status' => JobStatus::QUEUED ), array( '%d', '%s' ), array( '%d', '%s' ) );
		if ( 0 === $updated ) {
			// A fast runner can claim the new action before its ID is persisted.
			// Preserve an already-recorded action ID belonging to another attempt.
			$wpdb->update( $this->table, $data, array( 'id' => $id, 'status' => JobStatus::PROCESSING, 'action_id' => null ), array( '%d', '%s' ), array( '%d', '%s', '%d' ) );
		}
	}

	public function submitted( int $id, string $external_id ): bool {
		return $this->set_status( $id, JobStatus::SUBMITTED, JobStatus::PROCESSING, array( 'external_job_id' => sanitize_text_field( $external_id ), 'completed_at' => current_time( 'mysql', true ), 'error_code' => null, 'error_message' => null ) );
	}

	public function browser_ready( int $id ): bool {
		return $this->set_status( $id, JobStatus::SUBMITTED, JobStatus::QUEUED, array( 'external_job_id' => null, 'completed_at' => current_time( 'mysql', true ), 'error_code' => null, 'error_message' => null ) );
	}

	public function fail( int $id, string $status, string $code, string $message, string $expected = JobStatus::PROCESSING ): bool {
		if ( ! in_array( $status, array( JobStatus::FAILED, JobStatus::UNKNOWN ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid failure status.' );
		}
		return $this->set_status( $id, $status, $expected, array( 'error_code' => sanitize_key( $code ), 'error_message' => $this->sanitize_error( $message ), 'completed_at' => current_time( 'mysql', true ) ) );
	}

	public function requeue( int $id, string $code, string $message ): bool {
		return $this->set_status( $id, JobStatus::QUEUED, JobStatus::PROCESSING, array( 'action_id' => null, 'completed_at' => null, 'error_code' => sanitize_key( $code ), 'error_message' => $this->sanitize_error( $message ) ) );
	}

	public function cancel( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted table name.
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET status = %s, updated_at = %s, completed_at = %s WHERE id = %d AND status = %s", JobStatus::CANCELLED, current_time( 'mysql', true ), current_time( 'mysql', true ), $id, JobStatus::QUEUED ) );
	}

	public function retry_failed( int $id ): bool {
		global $wpdb;
		// Queue failures happened before submission; HTTP 429 definitively rejects
		// the request. An HTTP 5xx response can follow acceptance, so it is unsafe.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted table name.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET status = %s, action_id = NULL, completed_at = NULL, updated_at = %s WHERE id = %d AND status = %s AND attempt_count < %d AND error_code IN (%s, %s, %s)", JobStatus::QUEUED, current_time( 'mysql', true ), $id, JobStatus::FAILED, \WCInvoicePrinter\Printing\RetryPolicy::MAX_ATTEMPTS, 'http_429', 'scheduler_unavailable', 'schedule_failed' ) );
		return 1 === $updated;
	}

	public function recovery_candidates( int $after_id = 0, int $limit = 100 ): array {
		global $wpdb;
		// Browser jobs have no background action and must not be sent to a provider.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted table name.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE status IN (%s, %s) AND provider_id = %s AND id > %d ORDER BY id ASC LIMIT %d", JobStatus::QUEUED, JobStatus::PROCESSING, 'printnode', $after_id, max( 1, min( 100, $limit ) ) ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function find_by_action( int $action_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted table name.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE action_id = %d", $action_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function list( array $filters, int $page = 1, int $per_page = 20 ): array {
		global $wpdb;
		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$where  = array( '1=1' );
		$values = array();
		if ( ! empty( $filters['status'] ) && in_array( $filters['status'], JobStatus::cases(), true ) ) {
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
		$sql      = "SELECT SQL_CALC_FOUND_ROWS * FROM {$this->table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Conditions are allowlisted and table name is trusted.
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
		$total = (int) $wpdb->get_var( 'SELECT FOUND_ROWS()' );
		return array( 'items' => is_array( $rows ) ? $rows : array(), 'total' => $total );
	}

	private function set_status( int $id, string $status, string $expected, array $extra = array() ): bool {
		if ( ! in_array( $status, JobStatus::cases(), true ) || ! in_array( $expected, JobStatus::cases(), true ) ) {
			throw new \InvalidArgumentException( 'Invalid print job status.' );
		}
		global $wpdb;
		$data = array_merge( array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ), $extra );
		return 1 === $wpdb->update( $this->table, $data, array( 'id' => $id, 'status' => $expected ) );
	}

	private function sanitize_error( string $message ): string {
		$message = preg_replace( '/authorization\s*[:=]?\s*(?:basic|bearer)\s+[A-Za-z0-9+\/=._-]+/i', '[credential redacted]', $message );
		$message = preg_replace( '/(?:api[_ -]?key|authorization|basic)\s*[:=]?\s*[A-Za-z0-9+\/=._-]+/i', '[credential redacted]', $message );
		return mb_substr( sanitize_text_field( (string) $message ), 0, 500 );
	}
}
