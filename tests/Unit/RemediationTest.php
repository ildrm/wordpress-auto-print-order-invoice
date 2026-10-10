<?php

namespace WCInvoicePrinter\Tests\Unit;

use WCInvoicePrinter\Tests\Support\JobTestCase;

final class RemediationTest extends JobTestCase {
	public function test_documents_have_independent_template_identity(): void {
		$this->assertSame( 'shipping_label', $this->templates->get( 'shipping-label' )->document_type );
		$this->assertSame( 'packing_list', $this->templates->get( 'packing-list' )->document_type );
		$this->assertSame( 'invoice', $this->templates->get( 'classic' )->document_type );
	}

	public function test_confirmation_of_label_does_not_confirm_invoice(): void {
		$job = $this->job( array( 'document_type' => 'shipping_label', 'template_id' => 'shipping-label' ) );
		$this->db->rows[ $job['id'] ]['printed_at'] = '2026-10-10 12:00:00';
		$this->assertNull( $this->jobs->latest_confirmed_for_order( 42 ) );
		$this->assertNotNull( $this->jobs->latest_confirmed_for_order( 42, 'shipping_label' ) );
	}

	public function test_unpaid_order_print_state_is_ineligible(): void {
		$state = new \WCInvoicePrinter\PrintJob\DocumentPrintState( $this->jobs, $this->settings );
		$this->assertSame( 'ineligible', $state->for_order( new \WC_Order( 42, 'pending' ) )['state'] );
	}
}
