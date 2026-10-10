<?php
namespace WCInvoicePrinter\Infrastructure;

interface LeaseInterface {
	public function acquire( string $name, int $ttl = 120 ): bool;
	public function renew( string $name, int $ttl = 120 ): bool;
	public function release( string $name ): void;
}
