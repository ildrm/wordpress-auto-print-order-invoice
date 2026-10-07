<?php

namespace WCInvoicePrinter\PrintJob;

final class JobStatus {
	public const QUEUED = 'queued';
	public const PROCESSING = 'processing';
	public const SUBMITTED = 'submitted';
	public const FAILED = 'failed';
	public const UNKNOWN = 'unknown';
	public const CANCELLED = 'cancelled';

	private function __construct() {}

	/** @return string[] */
	public static function cases(): array {
		return array( self::QUEUED, self::PROCESSING, self::SUBMITTED, self::FAILED, self::UNKNOWN, self::CANCELLED );
	}

	public static function is_terminal( string $status ): bool {
		return in_array( $status, array( self::SUBMITTED, self::FAILED, self::UNKNOWN, self::CANCELLED ), true );
	}
}
