<?php
namespace WCInvoicePrinter\Tests\Unit;

use WCInvoicePrinter\Automation\PaidOrderReconciler;
use WCInvoicePrinter\Infrastructure\LeaseInterface;
use WCInvoicePrinter\Tests\Support\JobTestCase;

final class PaidOrderReconcilerTest extends JobTestCase {
	private function reconciler( bool $acquire = true ): PaidOrderReconciler {
		$lease = new class( $acquire ) implements LeaseInterface {
			private bool $available;
			public function __construct( bool $available ) { $this->available = $available; }
			public function acquire( string $name, int $ttl = 120 ): bool { return $this->available; }
			public function renew( string $name, int $ttl = 120 ): bool { return $this->available; }
			public function release( string $name ): void {}
		};
		return new PaidOrderReconciler( $this->settings, $this->service, $this->jobs, $lease );
	}
	protected function tearDown(): void { unset( $GLOBALS['wcip_order_query'], $GLOBALS['wcip_order_lookup'] ); parent::tearDown(); }
	public function test_missed_hook_is_discovered_by_payment_time_then_converges(): void {
		$order = new \WC_Order( 42 ); $GLOBALS['wcip_test_orders'][42] = $order;
		$GLOBALS['wcip_order_query'] = function ( array $args ): array {
			$this->assertArrayNotHasKey( 'date_created', $args );
			$this->assertSame( 50, $args['limit'] );
			return array( 42 );
		};
		$reconciler = $this->reconciler(); $this->assertSame( array(), $this->db->rows );
		$reconciler->run(); $reconciler->run(); $reconciler->run();
		$this->assertCount( 1, $this->db->rows );
		$this->assertSame( 1, get_option( 'wcip_reconciliation_stats' )['existing'] );
	}
	public function test_missing_printer_does_not_advance_discovery_cursor(): void {
		$this->settings->update( array( 'cups_printer_id' => '' ) );
		$state = array( 'from' => time() - 10, 'to' => time(), 'phase' => 'paid', 'page' => 7 );
		update_option( 'wcip_reconciliation_state', $state );
		$GLOBALS['wcip_order_query'] = static function (): array { throw new \LogicException( 'Must not query past blocked printer configuration.' ); };
		$this->reconciler()->run();
		$this->assertSame( $state, get_option( 'wcip_reconciliation_state' ) );
		$this->assertSame( 1, get_option( 'wcip_reconciliation_stats' )['blocked'] );
	}
	public function test_coordinator_lease_prevents_a_second_scan(): void {
		$GLOBALS['wcip_order_query'] = static function (): array { throw new \LogicException( 'No concurrent scan allowed.' ); };
		$this->reconciler( false )->run(); $this->assertSame( array(), $this->db->rows );
	}
	public function test_virtual_100000_order_catalog_is_bounded_and_resumes_after_first_page(): void {
		$queries = array();
		$GLOBALS['wcip_order_query'] = static function ( array $args ) use ( &$queries ): array {
			$queries[] = $args; $start = ( $args['paged'] - 1 ) * $args['limit'] + 1;
			return range( $start, min( 100000, $start + $args['limit'] - 1 ) );
		};
		$GLOBALS['wcip_order_lookup'] = static fn( int $id ) => new \WC_Order( $id );
		$before = memory_get_usage( true ); $reconciler = $this->reconciler(); $reconciler->run();
		$this->assertCount( 50, $this->db->rows ); $this->assertCount( 1, $queries );
		$this->assertSame( 2, get_option( 'wcip_reconciliation_state' )['page'] );
		$this->assertLessThan( 16 * 1024 * 1024, memory_get_usage( true ) - $before );
		$reconciler->run(); $this->assertCount( 100, $this->db->rows );
		$this->assertSame( 2, $queries[1]['paged'] );
	}
	public function test_backfill_dry_run_does_not_create_jobs_and_rejects_wide_windows(): void {
		$GLOBALS['wcip_order_query'] = static fn() => array( 42 ); $GLOBALS['wcip_test_orders'][42] = new \WC_Order( 42 );
		$this->assertSame( 42, $this->reconciler()->preview( time() - 60, time() )['items'][0]['order_id'] );
		$this->assertSame( array(), $this->db->rows );
		$this->expectException( \InvalidArgumentException::class ); $this->reconciler()->preview( 1, 33 * DAY_IN_SECONDS );
	}
}
