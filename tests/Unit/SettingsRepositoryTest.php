<?php

namespace WCInvoicePrinter\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WCInvoicePrinter\Settings\SettingsRepository;

final class SettingsRepositoryTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wcip_test_options'] = array();
		$GLOBALS['wcip_test_transients'] = array();
	}

	public function test_updates_normalize_values_and_reject_unknown_keys(): void {
		$settings = new SettingsRepository();
		$settings->update( array( 'business_name' => '<b>Store</b>', 'automatic_copies' => 99, 'automatic_enabled' => 'false', 'show_customer_note' => '1', 'printnode_api_key' => ' secret ', 'printnode_printer_id' => '123', 'unknown' => 'unexpected' ) );
		self::assertSame( 'Store', $settings->get( 'business_name' ) );
		self::assertSame( 20, $settings->get( 'automatic_copies' ) );
		self::assertFalse( $settings->get( 'automatic_enabled' ) );
		self::assertTrue( $settings->get( 'show_customer_note' ) );
		self::assertSame( 'secret', $settings->api_key() );
		self::assertArrayNotHasKey( 'unknown', $settings->all() );
	}

	#[DataProvider( 'invalid_printer_ids' )]
	public function test_invalid_printer_ids_are_not_rewritten_to_another_printer( mixed $value ): void {
		$settings = new SettingsRepository();
		$settings->update( array( 'printnode_printer_id' => $value ) );
		self::assertSame( '', $settings->get( 'printnode_printer_id' ) );
	}

	public static function invalid_printer_ids(): array {
		return array( array( '-123' ), array( 'printer123' ), array( '0' ), array( '00123' ), array( "123\n" ), array( array( '123' ) ) );
	}

	public function test_corrupt_storage_falls_back_to_typed_defaults(): void {
		$GLOBALS['wcip_test_options']['wcip_settings'] = array( 'business_name' => array( 'bad' ), 'automatic_copies' => -5, 'automatic_enabled' => 'false', 'printnode_api_key' => array( 'secret' ) );
		$settings = new SettingsRepository();
		self::assertSame( 'Test Store', $settings->get( 'business_name' ) );
		self::assertSame( 1, $settings->get( 'automatic_copies' ) );
		self::assertFalse( $settings->get( 'automatic_enabled' ) );
		self::assertSame( '', $settings->api_key() );
	}

	public function test_credential_change_invalidates_both_account_caches(): void {
		$settings = new SettingsRepository();
		$settings->update( array( 'printnode_api_key' => 'old' ) );
		foreach ( array( 'wcip_printnode_printers', 'wcip_printnode_printers_' . hash( 'sha256', 'old' ), 'wcip_printnode_printers_' . hash( 'sha256', 'new' ), 'unrelated' ) as $key ) { $GLOBALS['wcip_test_transients'][ $key ] = 'cached'; }
		$settings->update( array( 'printnode_api_key' => 'new' ) );
		self::assertSame( array( 'unrelated' => 'cached' ), $GLOBALS['wcip_test_transients'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_external_key_has_precedence_and_cannot_be_replaced(): void {
		define( 'WCIP_PRINTNODE_API_KEY', ' external ' );
		$GLOBALS['wcip_test_options']['wcip_settings'] = array( 'printnode_api_key' => 'stored' );
		$settings = new SettingsRepository();
		$settings->update( array( 'printnode_api_key' => 'replacement', 'business_name' => 'Updated' ) );
		self::assertTrue( $settings->api_key_is_external() );
		self::assertSame( 'external', $settings->api_key() );
		self::assertSame( 'stored', $settings->get( 'printnode_api_key' ) );
		self::assertSame( 'Updated', $settings->get( 'business_name' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_empty_external_constant_does_not_hide_stored_key(): void {
		define( 'WCIP_PRINTNODE_API_KEY', ' ' );
		$settings = new SettingsRepository();
		$settings->update( array( 'printnode_api_key' => 'stored' ) );
		self::assertFalse( $settings->api_key_is_external() );
		self::assertSame( 'stored', $settings->api_key() );
	}
}
