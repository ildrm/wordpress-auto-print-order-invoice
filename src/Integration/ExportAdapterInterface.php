<?php

namespace WCInvoicePrinter\Integration;

interface ExportAdapterInterface {
	public function id(): string;
	public function capabilities(): array;
	public function validate_configuration(): void;
	public function test_connection(): array;
	/** Return a receipt; throw a certainty-aware ProviderException on failure. */
	public function submit( string $payload, string $idempotency_key ): string;
}
