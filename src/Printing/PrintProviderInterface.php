<?php

namespace WCInvoicePrinter\Printing;

interface PrintProviderInterface {
	public function id(): string;

	public function test_connection(): array;

	public function printers( bool $force_refresh = false ): array;

	public function submit( string $pdf_bytes, string $printer_id, int $copies, string $title ): SubmissionResult;
}
