<?php

namespace WCInvoicePrinter\Automation;

use WCInvoicePrinter\PrintJob\PrintJobService;

final class AutomaticPrintHandler {
	public function __construct( private readonly PrintJobService $jobs ) {}

	public function payment_complete( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			$this->jobs->create_automatic( $order );
		}
	}

	public function paid_status_fallback( int $order_id, string $from, string $to, \WC_Order $order ): void {
		$enabled = apply_filters( 'wcip_enable_paid_status_fallback', true, $order, $from, $to );
		if ( $enabled && $order->is_paid() && in_array( $to, wc_get_is_paid_statuses(), true ) ) {
			$this->jobs->create_automatic( $order );
		}
	}
}
