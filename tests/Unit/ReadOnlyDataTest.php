<?php

namespace WCInvoicePrinter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCInvoicePrinter\Invoice\InvoiceFactory;
use WCInvoicePrinter\Printing\SubmissionResult;
use WCInvoicePrinter\Settings\SettingsRepository;
use WCInvoicePrinter\Template\TemplateDefinition;

final class ReadOnlyDataTest extends TestCase {
	/** @dataProvider read_only_fields */
	public function test_fields_are_readable_and_cannot_be_replaced( string $kind, string $field, $value ): void {
		$model = $this->model( $kind );
		self::assertSame( $value, $model->$field );
		self::assertTrue( isset( $model->$field ) );
		try {
			$model->$field = $value;
			self::fail( 'A read-only field was replaced.' );
		} catch ( \Error $error ) {
			self::assertSame( $value, $model->$field );
		}
	}

	/** @dataProvider read_only_fields */
	public function test_fields_cannot_be_unset( string $kind, string $field, $value ): void {
		$model = $this->model( $kind );
		try {
			unset( $model->$field );
			self::fail( 'A read-only field was unset.' );
		} catch ( \Error $error ) {
			self::assertSame( $value, $model->$field );
		}
	}

	public static function read_only_fields(): array {
		return array(
			array( 'invoice', 'rtl', false ),
			array( 'template', 'id', 'custom' ),
			array( 'template', 'supports_rtl', true ),
			array( 'submission', 'external_job_id', '123' ),
			array( 'submission', 'state', 'submitted' ),
		);
	}

	public function test_array_fields_are_returned_by_value_and_rtl_returns_a_new_invoice(): void {
		$invoice = $this->model( 'invoice' );
		$order = $invoice->order;
		$order['number'] = 'changed';
		self::assertSame( '1042', $invoice->order['number'] );
		$rtl = $invoice->with_rtl( true );
		self::assertNotSame( $invoice, $rtl );
		self::assertFalse( $invoice->rtl );
		self::assertTrue( $rtl->rtl );
		self::assertSame( $invoice->order, $rtl->order );
	}

	public function test_unknown_fields_do_not_create_dynamic_properties(): void {
		$model = $this->model( 'submission' );
		self::assertFalse( isset( $model->unknown ) );
		$this->expectException( \Error::class );
		$model->unknown = 'unexpected';
	}

	/** @dataProvider serialized_models */
	public function test_json_serialization_preserves_the_exposed_fields( string $kind, array $fields ): void {
		$model = $this->model( $kind );
		$data = json_decode( json_encode( $model ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( $fields, array_keys( $data ) );
		foreach ( $fields as $field ) {
			self::assertSame( $model->$field, $data[ $field ] );
		}
	}

	public static function serialized_models(): array {
		return array(
			array( 'invoice', array( 'order', 'store', 'customer', 'items', 'totals', 'fulfillment', 'rtl' ) ),
			array( 'template', array( 'id', 'name', 'description', 'paper_size', 'orientation', 'supports_rtl', 'path', 'document_type' ) ),
			array( 'submission', array( 'external_job_id', 'state' ) ),
		);
	}

	/** @dataProvider serialized_models */
	public function test_calling_constructor_again_cannot_change_read_only_data( string $kind, array $fields ): void {
		$model = $this->model( $kind );
		$original = $model->jsonSerialize();
		try {
			$model->__construct( ...array_values( $original ) );
			self::fail( 'Read-only data was reinitialized.' );
		} catch ( \Error $error ) {
			self::assertSame( $original, $model->jsonSerialize() );
		}
	}

	private function model( string $kind ): object {
		switch ( $kind ) {
			case 'invoice': return ( new InvoiceFactory( new SettingsRepository() ) )->sample();
			case 'template': return new TemplateDefinition( 'custom', 'Custom', '', 'A4', 'portrait', true, __FILE__ );
			default: return new SubmissionResult( '123' );
		}
	}
}
