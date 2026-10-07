<?php

namespace WCInvoicePrinter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCInvoicePrinter\PrintJob\JobStatus;

final class JobStatusTest extends TestCase {
	public function test_only_queued_and_processing_are_non_terminal(): void {
		self::assertFalse( JobStatus::is_terminal( JobStatus::QUEUED ) );
		self::assertFalse( JobStatus::is_terminal( JobStatus::PROCESSING ) );
		self::assertTrue( JobStatus::is_terminal( JobStatus::SUBMITTED ) );
		self::assertTrue( JobStatus::is_terminal( JobStatus::UNKNOWN ) );
		self::assertTrue( JobStatus::is_terminal( JobStatus::FAILED ) );
		self::assertTrue( JobStatus::is_terminal( JobStatus::CANCELLED ) );
	}
}
