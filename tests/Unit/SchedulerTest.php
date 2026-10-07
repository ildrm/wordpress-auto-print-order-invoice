<?php

namespace WCInvoicePrinter\Tests\Unit;

use WCInvoicePrinter\Tests\Support\JobTestCase;

final class SchedulerTest extends JobTestCase {
	public function test_initial_action_does_not_use_group_wide_uniqueness(): void {
		$job = $this->job();
		self::assertSame( 101, $this->scheduler->enqueue( $job['id'] ) );
		self::assertFalse( $GLOBALS['wcip_scheduled_action']['unique'] );
		self::assertSame( array( 'job_id' => $job['id'] ), $GLOBALS['wcip_scheduled_action']['args'] );
		self::assertSame( 101, $this->jobs->find( $job['id'] )['action_id'] );
	}

	public function test_different_jobs_can_queue_in_stores_that_deduplicate_by_hook_and_group(): void {
		$actions = array();
		$GLOBALS['wcip_async_action_callback'] = static function ( string $hook, array $args, string $group, bool $unique ) use ( &$actions ): int {
			// WooCommerce 9.0's DB store ignores args when unique is requested.
			if ( $unique && $actions ) { return 0; }
			$actions[] = compact( 'hook', 'args', 'group' );
			return 100 + count( $actions );
		};
		try {
			$first = $this->job();
			$second = $this->job( array( 'order_id' => 43 ) );
			self::assertSame( 101, $this->scheduler->enqueue( $first['id'] ) );
			self::assertSame( 102, $this->scheduler->enqueue( $second['id'] ) );
			self::assertSame( 'queued', $this->jobs->find( $first['id'] )['status'] );
			self::assertSame( 'queued', $this->jobs->find( $second['id'] )['status'] );
		} finally {
			unset( $GLOBALS['wcip_async_action_callback'] );
		}
	}

	public function test_delayed_retry_is_not_blocked_by_current_running_action(): void {
		$job = $this->job();
		self::assertSame( 102, $this->scheduler->enqueue( $job['id'], 60 ) );
		self::assertFalse( $GLOBALS['wcip_scheduled_action']['unique'] );
		self::assertGreaterThan( time(), $GLOBALS['wcip_scheduled_action']['timestamp'] );
	}

	public function test_scheduler_unavailable_is_recorded_and_safely_retryable(): void {
		$job = $this->job();
		\ActionScheduler::$initialized = false;
		self::assertSame( 0, $this->scheduler->enqueue( $job['id'] ) );
		self::assertSame( 'scheduler_unavailable', $this->jobs->find( $job['id'] )['error_code'] );
		self::assertTrue( $this->jobs->retry_failed( $job['id'] ) );
	}

	public function test_zero_action_id_and_thrown_queue_error_are_recorded_without_escaping(): void {
		$job = $this->job();
		$GLOBALS['wcip_async_action_result'] = 0;
		self::assertSame( 0, $this->scheduler->enqueue( $job['id'] ) );
		self::assertSame( 'schedule_failed', $this->jobs->find( $job['id'] )['error_code'] );
		$other = $this->job();
		$GLOBALS['wcip_async_action_exception'] = new \RuntimeException( 'queue database unavailable' );
		self::assertSame( 0, $this->scheduler->enqueue( $other['id'] ) );
		self::assertSame( 'failed', $this->jobs->find( $other['id'] )['status'] );
	}

	public function test_pending_unique_action_is_reused_instead_of_failing_the_job(): void {
		$job = $this->job();
		$GLOBALS['wcip_existing_action_id'] = 55;
		$GLOBALS['wcip_async_action_result'] = 0;
		self::assertSame( 55, $this->scheduler->enqueue( $job['id'] ) );
		self::assertSame( 'queued', $this->jobs->find( $job['id'] )['status'] );
		self::assertSame( 55, $this->jobs->find( $job['id'] )['action_id'] );
		self::assertArrayNotHasKey( 'wcip_scheduled_action', $GLOBALS );
	}

	public function test_late_failure_cannot_overwrite_a_concurrently_claimed_job(): void {
		$job = $this->job();
		$GLOBALS['wcip_async_action_result'] = 0;
		$this->db->before_update = function (): void { $this->jobs->claim( 1 ); };
		self::assertSame( 0, $this->scheduler->enqueue( $job['id'] ) );
		self::assertSame( 'processing', $this->jobs->find( $job['id'] )['status'] );
	}

	public function test_running_unique_action_cannot_fail_a_row_while_its_worker_arranges_a_retry(): void {
		$job = $this->job();
		$this->jobs->set_action_id( $job['id'], 55 );
		$GLOBALS['wcip_existing_action_id'] = 55;
		$GLOBALS['wcip_existing_action_status'] = 'in-progress';
		$GLOBALS['wcip_async_action_result'] = 0;
		self::assertSame( 55, $this->scheduler->enqueue( $job['id'] ) );
		self::assertSame( 'queued', $this->jobs->find( $job['id'] )['status'] );
		self::assertNull( $this->jobs->find( $job['id'] )['error_code'] );
		self::assertSame( 102, $this->scheduler->enqueue( $job['id'], 60 ) );
		self::assertSame( 102, $this->jobs->find( $job['id'] )['action_id'] );
	}

	public function test_cancelled_and_processing_jobs_are_not_scheduled(): void {
		$job = $this->job();
		$this->jobs->cancel( $job['id'] );
		self::assertSame( 0, $this->scheduler->enqueue( $job['id'] ) );
		$other = $this->job();
		$this->jobs->claim( $other['id'] );
		self::assertSame( 0, $this->scheduler->enqueue( $other['id'] ) );
		self::assertArrayNotHasKey( 'wcip_scheduled_action', $GLOBALS );
	}

	public function test_recovery_repairs_orphaned_action_ids_without_scheduling_browser_jobs(): void {
		$this->job( array( 'provider_id' => 'browser' ) );
		$job = $this->job();
		$this->jobs->set_action_id( $job['id'], 999 );
		$this->scheduler->recover();
		self::assertSame( array( 'job_id' => $job['id'] ), $GLOBALS['wcip_scheduled_action']['args'] );
		self::assertSame( 101, $this->jobs->find( $job['id'] )['action_id'] );
	}

	public function test_recovery_preserves_active_actions_and_disabled_scheduler(): void {
		$this->job();
		$GLOBALS['wcip_existing_action_id'] = 88;
		$this->scheduler->recover();
		self::assertArrayNotHasKey( 'wcip_scheduled_action', $GLOBALS );
		unset( $GLOBALS['wcip_existing_action_id'] );
		\ActionScheduler::$initialized = false;
		$this->scheduler->recover();
		self::assertSame( 'queued', $this->jobs->find( 1 )['status'] );
	}

	public function test_recovery_cursor_reaches_jobs_beyond_a_full_healthy_first_page(): void {
		for ( $i = 0; $i < 101; ++$i ) { $this->job(); }
		$GLOBALS['wcip_existing_action_id'] = 88;
		$this->scheduler->recover();
		self::assertSame( 100, get_option( 'wcip_recovery_cursor' ) );
		unset( $GLOBALS['wcip_existing_action_id'] );
		$this->scheduler->recover();
		self::assertSame( array( 'job_id' => 101 ), $GLOBALS['wcip_scheduled_action']['args'] );
		self::assertSame( 0, get_option( 'wcip_recovery_cursor' ) );
	}

	public function test_stale_processing_without_a_live_action_becomes_unknown_and_is_never_resubmitted(): void {
		$job = $this->job();
		$this->jobs->claim( $job['id'] );
		$this->db->rows[ $job['id'] ]['started_at'] = gmdate( 'Y-m-d H:i:s', time() - 601 );
		$this->scheduler->recover();
		self::assertSame( 'unknown', $this->jobs->find( $job['id'] )['status'] );
		self::assertSame( 'worker_interrupted', $this->jobs->find( $job['id'] )['error_code'] );
		self::assertArrayNotHasKey( 'wcip_scheduled_action', $GLOBALS );
		self::assertFalse( $this->jobs->retry_failed( $job['id'] ) );
	}

	public function test_processing_with_a_live_action_or_recent_start_is_preserved(): void {
		$active = $this->job();
		$this->jobs->claim( $active['id'] );
		$this->db->rows[ $active['id'] ]['started_at'] = gmdate( 'Y-m-d H:i:s', time() - 601 );
		$GLOBALS['wcip_existing_action_id'] = 88;
		$this->scheduler->recover();
		self::assertSame( 'processing', $this->jobs->find( $active['id'] )['status'] );
		unset( $GLOBALS['wcip_existing_action_id'] );
		$this->db->rows[ $active['id'] ]['started_at'] = gmdate( 'Y-m-d H:i:s' );
		$this->scheduler->recover();
		self::assertSame( 'processing', $this->jobs->find( $active['id'] )['status'] );
		self::assertArrayNotHasKey( 'wcip_scheduled_action', $GLOBALS );
	}

	public function test_recovery_preserves_rate_limit_backoff_for_an_orphaned_retry(): void {
		$job = $this->job();
		$this->jobs->claim( $job['id'] );
		$this->jobs->requeue( $job['id'], 'http_429', 'rejected' );
		$this->db->rows[ $job['id'] ]['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$this->scheduler->recover();
		self::assertSame( 102, $this->jobs->find( $job['id'] )['action_id'] );
		self::assertGreaterThanOrEqual( time() + 59, $GLOBALS['wcip_scheduled_action']['timestamp'] );
		self::assertFalse( $GLOBALS['wcip_scheduled_action']['unique'] );
	}
}
