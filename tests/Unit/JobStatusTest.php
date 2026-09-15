<?php

namespace WCInvoicePrinter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCInvoicePrinter\PrintJob\JobStatus;

final class JobStatusTest extends TestCase {
	public function test_only_queued_and_processing_are_non_terminal(): void {
		self::assertFalse( JobStatus::QUEUED->is_terminal() );
		self::assertFalse( JobStatus::PROCESSING->is_terminal() );
		self::assertTrue( JobStatus::SUBMITTED->is_terminal() );
		self::assertTrue( JobStatus::UNKNOWN->is_terminal() );
		self::assertTrue( JobStatus::FAILED->is_terminal() );
		self::assertTrue( JobStatus::CANCELLED->is_terminal() );
	}
}
