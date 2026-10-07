<?php

namespace WCInvoicePrinter\Automation;

use WCInvoicePrinter\PrintJob\JobStatus;
use WCInvoicePrinter\PrintJob\PrintJobRepository;

final class Scheduler {
	public const HOOK  = 'wcip_process_print_job';
	public const GROUP = 'wc-invoice-printer';

	private PrintJobRepository $jobs;

	public function __construct( PrintJobRepository $jobs ) {
		$this->jobs = $jobs;
	}

	public function enqueue( int $job_id, int $delay = 0 ): int {
		$job = $this->jobs->find( $job_id );
		if ( ! $job || JobStatus::QUEUED !== $job['status'] ) {
			return 0;
		}
		if ( ! $this->available() || ( $delay > 0 && ! function_exists( 'as_schedule_single_action' ) ) ) {
			$this->jobs->fail( $job_id, JobStatus::FAILED, 'scheduler_unavailable', __( 'The background queue is unavailable.', 'wc-invoice-printer' ), JobStatus::QUEUED );
			return 0;
		}
		$args = array( 'job_id' => $job_id );
		try {
			// A duplicate payment callback may race with the creator before the
			// action ID is saved. Reuse the pending action instead of failing its job.
			$action_id = $this->pending_action( $args );
			if ( ! $action_id ) {
				$action_id = $delay > 0
					// unique=true also matches the currently running action, which
					// would prevent a delayed retry from being scheduled inside it.
					? as_schedule_single_action( time() + $delay, self::HOOK, $args, self::GROUP, false )
					: as_enqueue_async_action( self::HOOK, $args, self::GROUP, true );
			}
			if ( ! $action_id ) {
				$action_id = $this->pending_action( $args );
				if ( ! $action_id && 0 === $delay && function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
					// A running action can reject a unique enqueue after this caller
					// read the queued row. Its worker may be arranging a safe retry.
					// Leave that state alone; recovery repairs any later orphan.
					return (int) ( $this->jobs->find( $job_id )['action_id'] ?? 0 );
				}
			}
		} catch ( \Throwable $exception ) {
			// Queue storage errors must not break a customer's payment callback.
			$action_id = 0;
		}
		if ( $action_id ) {
			$this->jobs->set_action_id( $job_id, (int) $action_id );
		} else {
			$this->jobs->fail( $job_id, JobStatus::FAILED, 'schedule_failed', __( 'The print job could not be queued.', 'wc-invoice-printer' ), JobStatus::QUEUED );
		}
		return (int) $action_id;
	}

	public function recover(): void {
		if ( ! $this->available() || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		// Advance a cursor so a large healthy queue cannot hide orphaned jobs
		// behind the first page. Each request examines at most 100 rows.
		try {
			$cursor = absint( get_option( 'wcip_recovery_cursor', 0 ) );
			$jobs   = $this->jobs->recovery_candidates( $cursor );
			foreach ( $jobs as $job ) {
				$cursor = (int) $job['id'];
				$args   = array( 'job_id' => $cursor );
				if ( JobStatus::PROCESSING === $job['status'] ) {
					$started = strtotime( (string) $job['started_at'] . ' UTC' );
					if ( false === $started || $started > time() - 10 * MINUTE_IN_SECONDS ) {
						continue;
					}
					if ( ! as_has_scheduled_action( self::HOOK, $args, self::GROUP ) && $this->jobs->fail( $cursor, JobStatus::UNKNOWN, 'worker_interrupted', __( 'The worker stopped before recording a result. Check PrintNode before reprinting.', 'wc-invoice-printer' ) ) ) {
						do_action( 'wcip_print_failure', $cursor, 'worker_interrupted', JobStatus::UNKNOWN );
					}
					continue;
				}
				if ( ! as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
					$delay = 0;
					if ( (int) $job['attempt_count'] > 0 && 'http_429' === $job['error_code'] ) {
						$updated = strtotime( $job['updated_at'] . ' UTC' );
						$delay   = max( 0, (int) $updated + ( new \WCInvoicePrinter\Printing\RetryPolicy() )->delay( (int) $job['attempt_count'] ) - time() );
					}
					$this->enqueue( $cursor, $delay );
				}
			}
			update_option( 'wcip_recovery_cursor', 100 === count( $jobs ) ? $cursor : 0, false );
		} catch ( \Throwable $exception ) {
			// Recovery is best effort; unavailable queue storage must not break
			// the WordPress request that initializes Action Scheduler.
		}
	}

	private function available(): bool {
		return function_exists( 'as_enqueue_async_action' ) && class_exists( 'ActionScheduler' ) && \ActionScheduler::is_initialized();
	}

	private function pending_action( array $args ): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}
		$actions = as_get_scheduled_actions( array( 'hook' => self::HOOK, 'args' => $args, 'group' => self::GROUP, 'status' => 'pending', 'per_page' => 1 ), 'ids' );
		return (int) ( $actions[0] ?? 0 );
	}
}
