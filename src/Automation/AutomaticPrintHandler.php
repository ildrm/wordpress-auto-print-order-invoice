<?php

namespace WCInvoicePrinter\Automation;

use WCInvoicePrinter\PrintJob\PrintJobService;

final class AutomaticPrintHandler {
	public function __construct( private readonly PrintJobService $jobs ) {}

	public function payment_complete( int $order_id ): void {
		try {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				$this->jobs->create_automatic( $order );
			}
		} catch ( \Throwable $exception ) {
			$this->report_error( $order_id, $exception );
		}
	}

	public function paid_status_fallback( int $order_id, string $from, string $to, \WC_Order $order ): void {
		try {
			$enabled = apply_filters( 'wcip_enable_paid_status_fallback', true, $order, $from, $to );
			if ( $enabled && $order->is_paid() && in_array( $to, wc_get_is_paid_statuses(), true ) ) {
				$this->jobs->create_automatic( $order );
			}
		} catch ( \Throwable $exception ) {
			$this->report_error( $order_id, $exception );
		}
	}

	private function report_error( int $order_id, \Throwable $exception ): void {
		// An auxiliary print queue must not prevent WooCommerce from recording
		// a successful payment when its database or an extension fails.
		try {
			do_action( 'wcip_automatic_print_error', $order_id, $exception );
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'An automatic print job could not be created.', array( 'source' => 'wc-invoice-printer', 'order_id' => $order_id ) );
			}
		} catch ( \Throwable $observer_error ) {
			// Error observers are also auxiliary to checkout/payment completion.
		}
	}
}
