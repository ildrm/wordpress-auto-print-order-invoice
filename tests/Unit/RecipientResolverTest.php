<?php

namespace WCInvoicePrinter\Tests\Unit;

use WCInvoicePrinter\Invoice\RecipientResolver;
use WCInvoicePrinter\Invoice\WeightCalculator;
use WCInvoicePrinter\Tests\Support\JobTestCase;

final class RecipientResolverTest extends JobTestCase {
	private function order( string $phone ): \WC_Order {
		return new class( 42, $phone ) extends \WC_Order {
			private string $phone;
			public function __construct( int $id, string $phone ) { parent::__construct( $id ); $this->phone = $phone; }
			public function get_shipping_phone(): string { return $this->phone; }
			public function get_billing_phone(): string { return '+44 20 1111'; }
			public function get_address( string $type ): array { return 'shipping' === $type ? array( 'first_name' => 'Gift recipient', 'address_1' => 'Recipient street' ) : array( 'first_name' => 'Buyer', 'address_1' => 'Buyer street', 'city' => 'London' ); }
			public function get_meta( string $key, bool $single ) { return array( '_phone' => '+98 21 555', '_unit' => '<b>12 & B</b>', '_invalid' => array( 'secret' ) )[ $key ] ?? ''; }
			public function needs_shipping_address(): bool { return true; }
		};
	}
	public function test_shipping_phone_wins_over_mapping_and_billing(): void {
		$this->settings->update( array( 'shipping_field_mapping' => '{"phone":"_phone","unit":"_unit"}' ) );
		$data = ( new RecipientResolver( $this->settings ) )->resolve( $this->order( '+81 3 123' ) );
		$this->assertSame( '+81 3 123', $data['phone'] );
		$this->assertSame( 'Gift recipient', $data['name'] );
		$this->assertSame( 'Recipient street', $data['address']['address_1'] );
		$this->assertSame( '12 & B', $data['extra']['unit'] );
		$this->assertContains( 'city', $data['inherited'] );
	}
	public function test_mapping_precedes_optional_billing_fallback_and_rejects_arrays(): void {
		$this->settings->update( array( 'shipping_field_mapping' => '{"phone":"_phone","unit":"_invalid"}', 'shipping_billing_fallback' => false ) );
		$data = ( new RecipientResolver( $this->settings ) )->resolve( $this->order( '' ) );
		$this->assertSame( '+98 21 555', $data['phone'] );
		$this->assertSame( array(), $data['extra'] ); $this->assertSame( array(), $data['inherited'] );
		$this->settings->update( array( 'shipping_field_mapping' => '{}' ) );
		$this->assertSame( '', ( new RecipientResolver( $this->settings ) )->resolve( $this->order( '' ) )['phone'] );
		$this->settings->update( array( 'shipping_billing_fallback' => true ) );
		$this->assertSame( '+44 20 1111', ( new RecipientResolver( $this->settings ) )->resolve( $this->order( '' ) )['phone'] );
	}
	public function test_missing_weight_is_unknown_and_recorded_zero_is_real_zero(): void {
		$unknown = new class { public function get_product() { return false; } };
		$zero = new class { public function get_meta( string $key, bool $single ) { return '0'; } public function get_product() { return false; } };
		$weight = new WeightCalculator();
		$this->assertNull( $weight->line( $unknown, 2.5 )['line_kg'] );
		$this->assertSame( 0.0, $weight->line( $zero, 2.5 )['line_kg'] );
	}
	public function test_frozen_item_weight_wins_over_changed_catalog_and_fractional_quantity(): void {
		$item = new class { public function get_meta( string $key, bool $single ) { return '1.25'; } public function get_product() { throw new \LogicException( 'Catalog must not be consulted.' ); } };
		$this->assertSame( array( 'unit_kg' => 1.25, 'line_kg' => 3.125, 'source' => 'order_item' ), ( new WeightCalculator() )->line( $item, 2.5 ) );
	}
}
