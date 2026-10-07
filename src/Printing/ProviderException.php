<?php

namespace WCInvoicePrinter\Printing;

final class ProviderException extends \RuntimeException {
	private string $error_code;
	private bool $retryable;
	private bool $ambiguous;

	public function __construct( string $message, string $error_code, bool $retryable, bool $ambiguous ) {
		$this->error_code = $error_code;
		$this->retryable = $retryable;
		$this->ambiguous = $ambiguous;
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
