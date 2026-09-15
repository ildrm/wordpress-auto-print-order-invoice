<?php

namespace WCInvoicePrinter\PrintJob;

enum JobStatus: string {
	case QUEUED = 'queued';
	case PROCESSING = 'processing';
	case SUBMITTED = 'submitted';
	case FAILED = 'failed';
	case UNKNOWN = 'unknown';
	case CANCELLED = 'cancelled';

	public function is_terminal(): bool {
		return in_array( $this, array( self::SUBMITTED, self::FAILED, self::UNKNOWN, self::CANCELLED ), true );
	}
}
