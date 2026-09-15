<?php

namespace WCInvoicePrinter\Printing;

final class ProviderException extends \RuntimeException {
	public function __construct(
		string $message,
		private readonly string $error_code,
		private readonly bool $retryable,
		private readonly bool $ambiguous
	) {
		parent::__construct( $message );
	}

	public function error_code(): string {
		return $this->error_code;
	}

	public function retryable(): bool {
		return $this->retryable;
	}

	public function ambiguous(): bool {
		return $this->ambiguous;
	}
}
