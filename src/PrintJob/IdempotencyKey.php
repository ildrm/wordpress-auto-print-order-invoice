<?php

namespace WCInvoicePrinter\PrintJob;

final class IdempotencyKey {
	public static function automatic( int $order_id, string $transaction_id ): string {
		// A WooCommerce order has one canonical paid-invoice event. Some gateways add or
		// replace transaction IDs after firing payment_complete, so the order ID is the
		// only stable identity that cannot accidentally create a second physical print.
		return hash( 'sha256', 'wcip:payment-complete:v1:' . $order_id );
	}

	public static function manual(): string {
		return hash( 'sha256', 'wcip:manual:' . wp_generate_uuid4() );
	}
}
