<?php

namespace WCInvoicePrinter\Automation;

use WCInvoicePrinter\PrintJob\JobStatus;
use WCInvoicePrinter\PrintJob\PrintJobRepository;

final class Scheduler {
	public const HOOK  = 'wcip_process_print_job';
	public const GROUP = 'wc-invoice-printer';

	public function __construct( private readonly PrintJobRepository $jobs ) {}

	public function enqueue( int $job_id, int $delay = 0 ): int {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! class_exists( 'Action_Scheduler' ) || ! \Action_Scheduler::is_initialized() ) {
			$this->jobs->fail( $job_id, JobStatus::FAILED, 'scheduler_unavailable', __( 'The background queue is unavailable.', 'wc-invoice-printer' ) );
			return 0;
		}
		$args = array( 'job_id' => $job_id );
		$action_id = $delay > 0
			// A delayed retry is created from inside the currently running action;
			// unique=true would match that running action and refuse the retry.
			? as_schedule_single_action( time() + $delay, self::HOOK, $args, self::GROUP, false )
			: as_enqueue_async_action( self::HOOK, $args, self::GROUP, true );
		if ( $action_id ) {
			$this->jobs->set_action_id( $job_id, (int) $action_id );
		} else {
			$this->jobs->fail( $job_id, JobStatus::FAILED, 'schedule_failed', __( 'The print job could not be queued.', 'wc-invoice-printer' ) );
		}
		return (int) $action_id;
	}
}
