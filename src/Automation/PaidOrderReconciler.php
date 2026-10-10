<?php

namespace WCInvoicePrinter\Automation;

use WCInvoicePrinter\Infrastructure\DatabaseLease;
use WCInvoicePrinter\PrintJob\PrintJobRepository;
use WCInvoicePrinter\PrintJob\PrintJobService;
use WCInvoicePrinter\Settings\SettingsRepository;

/** Discovery of missing logical jobs; separate from recovery of existing jobs. */
final class PaidOrderReconciler {
	public const HOOK = 'wcip_reconcile_paid_orders';
	public const BATCH = 50;
	private SettingsRepository $settings;
	private PrintJobService $service;
	private PrintJobRepository $jobs;
	private PaymentEligibilityPolicy $policy;
	private \WCInvoicePrinter\Infrastructure\LeaseInterface $lease;
	public function __construct( SettingsRepository $settings, PrintJobService $service, PrintJobRepository $jobs, ?\WCInvoicePrinter\Infrastructure\LeaseInterface $lease = null ) {
		$this->settings = $settings; $this->service = $service; $this->jobs = $jobs;
		$this->policy = new PaymentEligibilityPolicy( $settings );
		$this->lease = $lease ?? new DatabaseLease();
	}

	public function schedule(): void {
		if ( ! $this->settings->get( 'automatic_enabled' ) || ! function_exists( 'as_schedule_recurring_action' ) || ! \ActionScheduler::is_initialized() ) { return; }
		if ( ! get_option( 'wcip_discovery_since' ) ) { update_option( 'wcip_discovery_since', time(), false ); }
		if ( ! as_has_scheduled_action( self::HOOK, array(), Scheduler::GROUP ) ) {
			as_schedule_recurring_action( time() + 60, (int) $this->settings->get( 'reconciliation_interval', 600 ), self::HOOK, array(), Scheduler::GROUP, true );
		}
	}

	public function run(): void {
		if ( ! $this->settings->get( 'automatic_enabled' ) ) { return; }
		$lease = $this->lease;
		if ( ! $lease->acquire( 'paid_order_reconciliation' ) ) { return; }
		$stats = array( 'scanned' => 0, 'eligible' => 0, 'existing' => 0, 'enqueued' => 0, 'blocked' => 0, 'historical_skipped' => 0, 'errors' => 0, 'ran_at' => gmdate( 'c' ) );
		try {
			$since = (int) get_option( 'wcip_discovery_since', time() );
			$state = get_option( 'wcip_reconciliation_state', array() );
			if ( ! is_array( $state ) || empty( $state['to'] ) ) {
				$state = array( 'from' => $since, 'to' => min( time(), $since + 3600 ), 'phase' => 'paid', 'page' => 1 );
			}
			if ( ! $this->settings->automatic_ready() ) {
				$stats['blocked'] = 1; return;
			}
			$ids = $this->candidates( (int) $state['from'], (int) $state['to'], $state['phase'], (int) $state['page'] );
			foreach ( $ids as $id ) {
				if ( ! $lease->renew( 'paid_order_reconciliation' ) ) { throw new \RuntimeException( 'Coordinator lease expired.' ); }
				++$stats['scanned'];
				$order = wc_get_order( $id );
				if ( ! $order instanceof \WC_Order || ! $this->policy->eligible( $order ) ) { continue; }
				$paid = $order->get_date_paid();
				if ( $paid instanceof \DateTimeInterface && $paid->getTimestamp() < $since ) { ++$stats['historical_skipped']; continue; }
				++$stats['eligible'];
				$existing = $this->jobs->latest_for_order( $order->get_id(), 'automatic' );
				if ( $existing ) { ++$stats['existing']; $this->service->create_automatic( $order ); continue; }
				$job = $this->service->create_automatic( $order );
				if ( $job ) { ++$stats['enqueued']; } else { ++$stats['blocked']; }
			}
			// A blocked window is revisited when printer configuration is restored.
			if ( $stats['blocked'] ) { return; }
			if ( count( $ids ) === self::BATCH ) { ++$state['page']; }
			elseif ( 'paid' === $state['phase'] ) { $state['phase'] = 'modified'; $state['page'] = 1; }
			else {
				$watermark = (int) $state['to'];
				$state = array( 'from' => max( $since, $watermark - 3600 ), 'to' => min( time(), $watermark + 3600 ), 'phase' => 'paid', 'page' => 1 );
			}
			if ( ! $lease->renew( 'paid_order_reconciliation' ) ) { throw new \RuntimeException( 'Coordinator lease expired.' ); }
			update_option( 'wcip_reconciliation_state', $state, false );
		} catch ( \Throwable $error ) { ++$stats['errors']; }
		finally {
			update_option( 'wcip_reconciliation_stats', $stats, false );
			$lease->release( 'paid_order_reconciliation' );
		}
	}

	/** Bounded dry-run. No jobs or provider calls are created. */
	public function preview( int $from, int $to, int $page = 1 ): array {
		if ( $from < 1 || $to < $from || $to - $from > 31 * DAY_IN_SECONDS || $page < 1 || $page > 2000 ) { throw new \InvalidArgumentException( 'Choose a date window of at most 31 days and a valid page.' ); }
		$ids = array_values( array_unique( array_merge( $this->candidates( $from, $to, 'paid', $page ), $this->candidates( $from, $to, 'modified', $page ) ) ) );
		$rows = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof \WC_Order ) { continue; }
			$job = $this->jobs->latest_for_order( $id, 'automatic' );
			$rows[] = array( 'order_id' => $id, 'number' => $order->get_order_number(), 'eligible' => $this->policy->eligible( $order ), 'existing_job_id' => $job['id'] ?? null, 'status' => $job['status'] ?? null );
		}
		return array( 'items' => $rows, 'page' => $page, 'next_page' => count( $ids ) >= self::BATCH ? $page + 1 : null, 'from' => $from, 'to' => $to );
	}

	private function candidates( int $from, int $to, string $phase, int $page ): array {
		$ids = wc_get_orders( array( 'type' => 'shop_order', 'status' => wc_get_is_paid_statuses(), 'return' => 'ids', 'limit' => self::BATCH, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'date_' . ( 'paid' === $phase ? 'paid' : 'modified' ) => $from . '...' . $to ) );
		return array_map( 'absint', is_array( $ids ) ? $ids : array() );
	}
}
