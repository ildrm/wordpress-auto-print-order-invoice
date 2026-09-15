<?php

namespace WCInvoicePrinter\Printing;

final class SubmissionResult {
	public function __construct(
		public readonly string $external_job_id,
		public readonly string $state = 'submitted'
	) {}
}
