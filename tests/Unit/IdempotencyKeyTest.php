<?php

namespace WCInvoicePrinter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCInvoicePrinter\PrintJob\IdempotencyKey;

final class IdempotencyKeyTest extends TestCase {
	public function test_same_payment_event_has_same_key(): void {
		self::assertSame( IdempotencyKey::automatic( 42, 'txn-9' ), IdempotencyKey::automatic( 42, 'txn-9' ) );
	}

	public function test_gateway_transaction_id_changes_cannot_duplicate_an_order_invoice(): void {
		self::assertSame( IdempotencyKey::automatic( 42, 'txn-9' ), IdempotencyKey::automatic( 42, 'txn-10' ) );
	}

	public function test_missing_transaction_falls_back_to_stable_order_identity(): void {
		self::assertSame( IdempotencyKey::automatic( 42, '' ), IdempotencyKey::automatic( 42, '') );
	}

	public function test_manual_reprints_are_always_unique(): void {
		self::assertNotSame( IdempotencyKey::manual(), IdempotencyKey::manual() );
	}

	public function test_different_orders_with_the_same_transaction_cannot_share_a_job(): void {
		self::assertNotSame( IdempotencyKey::automatic( 42, 'same-transaction' ), IdempotencyKey::automatic( 43, 'same-transaction' ) );
	}
}
