<?php

namespace WCInvoicePrinter\Tests\Unit;

use WCInvoicePrinter\PrintJob\JobStatus;
use WCInvoicePrinter\Tests\Support\JobTestCase;

final class PrintJobRepositoryTest extends JobTestCase {
	public function test_insert_failure_is_explicit(): void {
		$this->db->fail_insert = true;
		$this->expectException( \RuntimeException::class );
		$this->job();
	}

	public function test_duplicate_insert_returns_the_canonical_job(): void {
		$first = $this->job( array( 'idempotency_key' => 'same-key' ) );
		$second = $this->job( array( 'idempotency_key' => 'same-key', 'copies' => 10 ) );
		self::assertSame( $first, $second );
		self::assertCount( 1, $this->db->rows );
	}

	public function test_creation_looks_up_the_same_sanitized_key_that_it_saved(): void {
		$job = $this->job( array( 'idempotency_key' => ' <b>safe-key</b> ' ) );
		self::assertSame( 'safe-key', $job['idempotency_key'] );
	}

	public function test_atomic_claim_only_claims_a_queued_job_once(): void {
		$job = $this->job();
		self::assertTrue( $this->jobs->claim( $job['id'] ) );
		self::assertFalse( $this->jobs->claim( $job['id'] ) );
		self::assertSame( 1, $this->jobs->find( $job['id'] )['attempt_count'] );
	}

	public function test_scheduler_failure_cannot_overwrite_a_claimed_job(): void {
		$job = $this->job();
		$this->jobs->claim( $job['id'] );
		self::assertFalse( $this->jobs->fail( $job['id'], JobStatus::FAILED, 'schedule_failed', 'Queue error', JobStatus::QUEUED ) );
		self::assertSame( 'processing', $this->jobs->find( $job['id'] )['status'] );
	}

	public function test_fast_runner_can_receive_its_action_id_after_claiming_without_overwriting_an_existing_one(): void {
		$job = $this->job();
		$this->jobs->claim( $job['id'] );
		$this->jobs->set_action_id( $job['id'], 55 );
		self::assertSame( 55, $this->jobs->find( $job['id'] )['action_id'] );
		$this->jobs->set_action_id( $job['id'], 66 );
		self::assertSame( 55, $this->jobs->find( $job['id'] )['action_id'] );
	}

	public function test_submitted_and_cancelled_jobs_cannot_be_requeued_or_failed(): void {
		$job = $this->job();
		$this->jobs->claim( $job['id'] );
		self::assertTrue( $this->jobs->submitted( $job['id'], '99' ) );
		self::assertFalse( $this->jobs->requeue( $job['id'], 'http_429', 'retry' ) );
		self::assertFalse( $this->jobs->fail( $job['id'], JobStatus::FAILED, 'late_error', 'late error' ) );
		self::assertSame( 'submitted', $this->jobs->find( $job['id'] )['status'] );
		$cancelled = $this->job();
		self::assertTrue( $this->jobs->cancel( $cancelled['id'] ) );
		self::assertFalse( $this->jobs->browser_ready( $cancelled['id'] ) );
		self::assertFalse( $this->jobs->fail( $cancelled['id'], JobStatus::FAILED, 'schedule_failed', 'late error', JobStatus::QUEUED ) );
	}

	public function test_requeue_clears_stale_scheduler_and_completion_metadata(): void {
		$job = $this->job();
		$this->jobs->set_action_id( $job['id'], 123 );
		$this->jobs->claim( $job['id'] );
		$this->db->rows[ $job['id'] ]['completed_at'] = '2026-01-01 00:00:00';
		self::assertTrue( $this->jobs->requeue( $job['id'], 'http_429', 'rejected' ) );
		$row = $this->jobs->find( $job['id'] );
		self::assertNull( $row['action_id'] );
		self::assertNull( $row['completed_at'] );
		self::assertSame( 1, $row['attempt_count'] );
	}

	public function test_safe_retry_rejects_unknown_http_500_and_exhausted_jobs(): void {
		foreach ( array( 'http_500', 'transport_unknown', 'malformed_response' ) as $code ) {
			$job = $this->job();
			$this->jobs->claim( $job['id'] );
			$this->jobs->fail( $job['id'], JobStatus::FAILED, $code, 'failure' );
			self::assertFalse( $this->jobs->retry_failed( $job['id'] ) );
		}
		$unknown = $this->job();
		$this->jobs->claim( $unknown['id'] );
		$this->jobs->fail( $unknown['id'], JobStatus::UNKNOWN, 'http_429', 'ambiguous' );
		self::assertFalse( $this->jobs->retry_failed( $unknown['id'] ) );
		$exhausted = $this->job();
		$this->db->rows[ $exhausted['id'] ] = array_merge( $exhausted, array( 'status' => 'failed', 'attempt_count' => 3, 'error_code' => 'http_429' ) );
		self::assertFalse( $this->jobs->retry_failed( $exhausted['id'] ) );
	}

	public function test_safe_retry_allows_rejections_and_pre_submission_queue_failures(): void {
		foreach ( array( 'http_429', 'scheduler_unavailable', 'schedule_failed' ) as $code ) {
			$job = $this->job();
			$this->jobs->fail( $job['id'], JobStatus::FAILED, $code, 'failure', JobStatus::QUEUED );
			self::assertTrue( $this->jobs->retry_failed( $job['id'] ) );
			self::assertSame( 'queued', $this->jobs->find( $job['id'] )['status'] );
		}
	}

	public function test_same_second_latest_order_job_is_deterministic(): void {
		$this->job();
		$latest = $this->job();
		self::assertSame( $latest['id'], $this->jobs->latest_for_order( 42 )['id'] );
		self::assertSame( $latest['id'], $this->jobs->latest_for_order( 42, 'automatic' )['id'] );
	}

	public function test_pagination_is_bounded_and_cancelled_browser_jobs_are_not_recovered(): void {
		$this->job( array( 'provider_id' => 'browser' ) );
		$this->job();
		$this->jobs->list( array(), -5, -2 );
		self::assertStringContainsString( 'LIMIT 1 OFFSET 0', $this->db->queries[ count( $this->db->queries ) - 1 ] );
		self::assertCount( 1, $this->jobs->recovery_candidates() );
	}

	public function test_failure_diagnostics_redact_basic_and_bearer_credentials(): void {
		$job = $this->job();
		$this->jobs->claim( $job['id'] );
		$this->jobs->fail( $job['id'], JobStatus::FAILED, 'error', 'Authorization: Basic c2VjcmV0Og== Authorization: Bearer abc123 api_key=supersecret' );
		$message = $this->jobs->find( $job['id'] )['error_message'];
		self::assertStringNotContainsString( 'c2VjcmV0', $message );
		self::assertStringNotContainsString( 'abc123', $message );
		self::assertStringNotContainsString( 'supersecret', $message );
	}

	public function test_list_combines_allowlisted_filters_and_returns_consistent_totals(): void {
		$this->job();
		$this->job( array( 'trigger_type' => 'manual', 'provider_id' => 'browser' ) );
		$selected = $this->job( array( 'order_id' => 43 ) );
		$this->jobs->claim( $selected['id'] );
		$result = $this->jobs->list( array( 'status' => 'processing', 'trigger' => 'automatic', 'order_id' => 43 ) );
		self::assertSame( 1, $result['total'] );
		self::assertSame( $selected['id'], $result['items'][0]['id'] );
		$invalid = $this->jobs->list( array( 'status' => "queued' OR 1=1", 'trigger' => 'bogus' ), 2, 1 );
		self::assertSame( 3, $invalid['total'] );
		self::assertSame( 2, $invalid['items'][0]['id'] );
	}
}
