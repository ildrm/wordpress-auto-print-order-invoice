<?php

namespace WCInvoicePrinter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCInvoicePrinter\Admin\AdminPage;
use WCInvoicePrinter\PrintJob\PrintJobRepository;
use WCInvoicePrinter\Settings\SettingsRepository;
use WCInvoicePrinter\Template\TemplateRegistry;
use WCInvoicePrinter\Tests\Support\InMemoryWpdb;

final class AdminPageTest extends TestCase {
	private AdminPage $page;

	protected function setUp(): void {
		$GLOBALS['wpdb'] = new InMemoryWpdb();
		$GLOBALS['wcip_test_options'] = array( 'wcip_settings' => array( 'business_name' => 'Existing' ) );
		$GLOBALS['wcip_test_capabilities'] = array();
		$GLOBALS['wcip_test_nonce_valid'] = true;
		$_POST = array( 'section' => 'general', 'business_name' => 'New' );
		$this->page = new AdminPage( new SettingsRepository(), new TemplateRegistry(), new PrintJobRepository() );
	}

	protected function tearDown(): void {
		$_POST = array();
		$GLOBALS['wcip_test_nonce_valid'] = true;
	}

	private function assert_save_fails( int $status ): void {
		try { $this->page->save(); self::fail( 'Settings unexpectedly saved.' ); }
		catch ( \RuntimeException $error ) { self::assertSame( $status, $error->getCode() ); }
		self::assertSame( 'Existing', $GLOBALS['wcip_test_options']['wcip_settings']['business_name'] );
	}

	public function test_settings_save_requires_management_capability(): void { $this->assert_save_fails( 403 ); }

	public function test_settings_save_rejects_invalid_nonce(): void {
		$GLOBALS['wcip_test_capabilities']['wcip_manage_settings'] = true;
		$GLOBALS['wcip_test_nonce_valid'] = false;
		$this->assert_save_fails( 0 );
	}

	public function test_unknown_settings_section_is_rejected(): void {
		$GLOBALS['wcip_test_capabilities']['wcip_manage_settings'] = true;
		$_POST['section'] = 'unknown';
		$this->assert_save_fails( 400 );
	}

	public function test_array_settings_input_is_rejected_instead_of_resetting_values(): void {
		$GLOBALS['wcip_test_capabilities']['wcip_manage_settings'] = true;
		$_POST['business_name'] = array( 'New' );
		$this->assert_save_fails( 400 );
	}
}
