<?php

namespace WCInvoicePrinter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCInvoicePrinter\Automation\Scheduler;
use WCInvoicePrinter\PrintJob\PrintJobRepository;

final class SchedulerTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public array $updates = array();
			public function update( string $table, array $data, array $where, array $formats = array(), array $where_formats = array() ): int { $this->updates[] = compact( 'table', 'data', 'where' ); return 1; }
		};
	}

	public function test_initial_action_is_unique(): void {
		$scheduler = new Scheduler( new PrintJobRepository() );
		self::assertSame( 101, $scheduler->enqueue( 7 ) );
		self::assertTrue( $GLOBALS['wcip_scheduled_action']['unique'] );
		self::assertSame( array( 'job_id' => 7 ), $GLOBALS['wcip_scheduled_action']['args'] );
	}

	public function test_delayed_retry_is_not_blocked_by_current_running_action(): void {
		$scheduler = new Scheduler( new PrintJobRepository() );
		self::assertSame( 102, $scheduler->enqueue( 7, 60 ) );
		self::assertFalse( $GLOBALS['wcip_scheduled_action']['unique'] );
		self::assertGreaterThan( time(), $GLOBALS['wcip_scheduled_action']['timestamp'] );
	}
}
