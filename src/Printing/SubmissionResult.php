<?php

namespace WCInvoicePrinter\Printing;

use WCInvoicePrinter\Support\ReadOnlyProperties;

/**
 * @property-read string $external_job_id
 * @property-read string $state
 */
final class SubmissionResult implements \JsonSerializable {
	use ReadOnlyProperties;

	private string $external_job_id;
	private string $state;

	public function __construct( string $external_job_id, string $state = 'submitted' ) {
		$this->assert_uninitialized();
		$this->external_job_id = $external_job_id;
		$this->state = $state;
	}
}
