<?php

namespace WCInvoicePrinter\Tests\Unit;

use WCInvoicePrinter\Automation\AutomaticPrintHandler;
use WCInvoicePrinter\Tests\Support\JobTestCase;

final class AutomaticPrintHandlerTest extends JobTestCase {
	public function test_payment_completion_and_paid_status_fallback_share_one_invoice(): void {
		$order = new \WC_Order( 42 );
		$GLOBALS['wcip_test_orders'][42] = $order;
		$handler = new AutomaticPrintHandler( $this->service );
		$handler->payment_complete( 42 );
		$handler->paid_status_fallback( 42, 'pending', 'processing', $order );
		self::assertCount( 1, $this->db->rows );
	}

	public function test_missing_and_unpaid_orders_do_not_print(): void {
		$handler = new AutomaticPrintHandler( $this->service );
		$handler->payment_complete( 999 );
		$order = new \WC_Order( 42, 'pending' );
		$GLOBALS['wcip_test_orders'][42] = $order;
		$handler->payment_complete( 42 );
		$handler->paid_status_fallback( 42, 'pending', 'on-hold', $order );
		self::assertCount( 0, $this->db->rows );
	}

	public function test_paid_fallback_can_be_disabled_per_gateway(): void {
		$GLOBALS['wcip_test_filters']['wcip_enable_paid_status_fallback'][] = static fn() => false;
		( new AutomaticPrintHandler( $this->service ) )->paid_status_fallback( 42, 'pending', 'processing', new \WC_Order( 42 ) );
		self::assertCount( 0, $this->db->rows );
	}

	public function test_queue_persistence_failure_does_not_break_payment_completion(): void {
		$this->db->fail_insert = true;
		$GLOBALS['wcip_test_orders'][42] = new \WC_Order( 42 );
		( new AutomaticPrintHandler( $this->service ) )->payment_complete( 42 );
		self::assertCount( 0, $this->db->rows );
		self::assertSame( 'wcip_automatic_print_error', $GLOBALS['wcip_test_actions'][0][0] );
	}

	public function test_throwing_error_observer_cannot_break_payment_completion(): void {
		$this->db->fail_insert = true;
		$GLOBALS['wcip_test_orders'][42] = new \WC_Order( 42 );
		$GLOBALS['wcip_test_hooks']['wcip_automatic_print_error'][] = static function (): void { throw new \RuntimeException( 'observer failed' ); };
		( new AutomaticPrintHandler( $this->service ) )->payment_complete( 42 );
		self::assertCount( 0, $this->db->rows );
	}

	public function test_throwing_fallback_filter_cannot_break_order_status_updates(): void {
		$GLOBALS['wcip_test_filters']['wcip_enable_paid_status_fallback'][] = static function () { throw new \RuntimeException( 'Eligibility extension failed' ); };
		( new AutomaticPrintHandler( $this->service ) )->paid_status_fallback( 42, 'pending', 'processing', new \WC_Order( 42 ) );
		self::assertCount( 0, $this->db->rows );
		self::assertSame( 'wcip_automatic_print_error', $GLOBALS['wcip_test_actions'][0][0] );
	}
}
