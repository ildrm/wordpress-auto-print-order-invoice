<?php

namespace WCInvoicePrinter\Tests\Unit;

use WCInvoicePrinter\Automation\PaymentEligibilityPolicy;
use WCInvoicePrinter\Tests\Support\JobTestCase;

final class PaymentEligibilityPolicyTest extends JobTestCase {
	/** @dataProvider ineligible_statuses */
	public function test_unpaid_and_reversed_orders_are_not_confirmed( string $status ): void {
		$policy = new PaymentEligibilityPolicy( $this->settings );
		$this->assertFalse( $policy->confirmed( new \WC_Order( 42, $status ) ) );
		$this->assertNull( $this->service->create_automatic( new \WC_Order( 42, $status ) ) );
	}
	public static function ineligible_statuses(): array { return array_map( static fn( $status ) => array( $status ), array( 'pending', 'on-hold', 'failed', 'cancelled', 'refunded' ) ); }
	public function test_paid_status_without_payment_timestamp_is_not_confirmation(): void {
		$order = new class( 42 ) extends \WC_Order { public function get_date_paid() { return null; } };
		$this->assertFalse( ( new PaymentEligibilityPolicy( $this->settings ) )->confirmed( $order ) );
		$this->assertNull( $this->service->create_automatic( $order ) );
	}
	public function test_offline_processing_is_fulfillable_without_claiming_collection(): void {
		$GLOBALS['wcip_test_filters']['wcip_payment_method_semantics'][] = static fn() => 'offline';
		$order = new \WC_Order( 42 ); $policy = new PaymentEligibilityPolicy( $this->settings );
		$this->assertFalse( $policy->confirmed( $order ) );
		$this->assertNull( $this->service->create_automatic( $order ) );
		$this->settings->update( array( 'automatic_eligibility' => 'fulfillment' ) );
		$this->assertTrue( $policy->eligible( $order ) );
		$this->assertFalse( $policy->confirmed( $order ) );
		$this->assertNotNull( $this->service->create_automatic( $order ) );
	}
	public function test_gateway_adapter_can_confirm_a_missing_date_but_not_a_refund(): void {
		$GLOBALS['wcip_test_filters']['wcip_payment_confirmed'][] = static fn() => true;
		$order = new class( 42 ) extends \WC_Order { public function get_date_paid() { return null; } };
		$policy = new PaymentEligibilityPolicy( $this->settings );
		$this->assertTrue( $policy->confirmed( $order ) );
		$order->set_status( 'refunded' ); $this->assertFalse( $policy->confirmed( $order ) );
	}
	public function test_enablement_resets_discovery_cutoff_without_creating_jobs(): void {
		$this->settings->update( array( 'automatic_enabled' => false ) );
		$GLOBALS['wcip_test_options']['wcip_discovery_since'] = 1;
		$GLOBALS['wcip_test_options']['wcip_reconciliation_state'] = array( 'from' => 1 );
		$this->settings->update( array( 'automatic_enabled' => true ) );
		$this->assertGreaterThanOrEqual( time() - 1, get_option( 'wcip_discovery_since' ) );
		$this->assertFalse( get_option( 'wcip_reconciliation_state' ) );
		$this->assertSame( array(), $this->db->rows );
	}
}
