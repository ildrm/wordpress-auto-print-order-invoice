<?php

namespace WCInvoicePrinter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCInvoicePrinter\Settings\SettingsRepository;

final class SettingsRepositoryTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wcip_test_options'] = array();
		$GLOBALS['wcip_test_transients'] = array();
	}

	public function test_updates_normalize_values_and_reject_unknown_keys(): void {
		$settings = new SettingsRepository();
		$settings->update( array( 'business_name' => '<b>Store</b>', 'automatic_copies' => 99, 'automatic_enabled' => 'false', 'show_customer_note' => '1', 'cups_password' => 'secret', 'cups_printer_id' => 'Office_A4', 'unknown' => 'unexpected' ) );
		self::assertSame( 'Store', $settings->get( 'business_name' ) );
		self::assertSame( 20, $settings->get( 'automatic_copies' ) );
		self::assertFalse( $settings->get( 'automatic_enabled' ) );
		self::assertTrue( $settings->get( 'show_customer_note' ) );
		self::assertSame( 'secret', $settings->cups_password() );
		self::assertArrayNotHasKey( 'unknown', $settings->all() );
	}

	/**
	 * @dataProvider invalid_printer_ids
	 */
	public function test_invalid_printer_ids_are_not_rewritten_to_another_printer( $value ): void {
		$settings = new SettingsRepository();
		$settings->update( array( 'cups_printer_id' => $value ) );
		self::assertSame( '', $settings->get( 'cups_printer_id' ) );
	}

	public static function invalid_printer_ids(): array {
		return array( array( '-123' ), array( '../office' ), array( 'office/a' ), array( 'office?x=1' ), array( "123\n" ), array( array( '123' ) ) );
	}

	public function test_corrupt_storage_falls_back_to_typed_defaults(): void {
		$GLOBALS['wcip_test_options']['wcip_settings'] = array( 'business_name' => array( 'bad' ), 'automatic_copies' => -5, 'automatic_enabled' => 'false', 'cups_endpoint' => array( 'secret' ) );
		$settings = new SettingsRepository();
		self::assertSame( 'Test Store', $settings->get( 'business_name' ) );
		self::assertSame( 1, $settings->get( 'automatic_copies' ) );
		self::assertFalse( $settings->get( 'automatic_enabled' ) );
		self::assertSame( '', $settings->cups_endpoint() );
	}

	public function test_credential_change_invalidates_both_account_caches(): void {
		$settings = new SettingsRepository();
		$settings->update( array( 'cups_password' => 'old' ) ); $old = $settings->cups_fingerprint();
		$settings->update( array( 'cups_password' => 'new' ) ); $new = $settings->cups_fingerprint();
		$settings->update( array( 'cups_password' => 'old' ) );
		foreach ( array( 'wcip_cups_printers_' . $old, 'wcip_cups_printers_' . $new, 'unrelated' ) as $key ) { $GLOBALS['wcip_test_transients'][ $key ] = 'cached'; }
		$settings->update( array( 'cups_password' => 'new' ) );
		self::assertSame( array( 'unrelated' => 'cached' ), $GLOBALS['wcip_test_transients'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_external_key_has_precedence_and_cannot_be_replaced(): void {
		define( 'WCIP_CUPS_PASSWORD', 'external' );
		$GLOBALS['wcip_test_options']['wcip_settings'] = array( 'cups_password' => 'stored' );
		$settings = new SettingsRepository();
		$settings->update( array( 'cups_password' => 'replacement', 'business_name' => 'Updated' ) );
		self::assertTrue( $settings->cups_password_is_external() );
		self::assertSame( 'external', $settings->cups_password() );
		self::assertSame( 'stored', $settings->get( 'cups_password' ) );
		self::assertSame( 'Updated', $settings->get( 'business_name' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_empty_external_constant_does_not_hide_stored_key(): void {
		define( 'WCIP_CUPS_PASSWORD', ' ' );
		$settings = new SettingsRepository();
		$settings->update( array( 'cups_password' => 'stored' ) );
		self::assertFalse( $settings->cups_password_is_external() );
		self::assertSame( 'stored', $settings->cups_password() );
	}
}
