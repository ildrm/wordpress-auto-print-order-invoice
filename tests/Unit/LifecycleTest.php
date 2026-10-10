<?php

namespace WCInvoicePrinter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCInvoicePrinter\Infrastructure\Activator;

final class LifecycleTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wcip_test_options'] = array();
		$GLOBALS['wcip_test_roles'] = array();
		$GLOBALS['wcip_unscheduled_actions'] = array();
		$GLOBALS['wpdb'] = new class {
			public string $prefix = 'wp_';
			public array $updates = array();
			public array $queries = array();
			public function update( string $table, array $data, array $where, array $formats = array(), array $where_formats = array() ): int { $this->updates[] = compact( 'table', 'data', 'where' ); return 1; }
			public function query( string $sql ): int { $this->queries[] = $sql; return 1; }
		};
	}

	public function test_deactivation_cancels_own_actions_and_releases_pending_job_action_ids(): void {
		$GLOBALS['wcip_test_options']['wcip_settings'] = array( 'automatic_enabled' => true );
		Activator::deactivate();
		self::assertSame( array( 'hook' => 'wcip_process_print_job', 'args' => array(), 'group' => 'wc-invoice-printer' ), $GLOBALS['wcip_unscheduled_actions'][0] );
		self::assertSame( array( 'status' => 'queued' ), $GLOBALS['wpdb']->updates[0]['where'] );
		self::assertSame( array( 'action_id' => null ), $GLOBALS['wpdb']->updates[0]['data'] );
		self::assertTrue( $GLOBALS['wcip_test_options']['wcip_settings']['automatic_enabled'] );
	}

	public function test_uninstall_preserves_data_by_default_and_removes_plugin_permissions(): void {
		$GLOBALS['wcip_test_options']['wcip_settings'] = array( 'business_name' => 'Retained' );
		$administrator_role = new class {
			public array $removed = array();
			public function remove_cap( string $capability ): void { $this->removed[] = $capability; }
		};
		$GLOBALS['wcip_test_roles']['administrator'] = $administrator_role;
		defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', 'wc-invoice-printer.php' );
		require WCIP_PATH . 'uninstall.php';
		self::assertSame( array(), $GLOBALS['wpdb']->queries );
		self::assertSame( 'Retained', $GLOBALS['wcip_test_options']['wcip_settings']['business_name'] );
		self::assertContains( 'wcip_manage_settings', $administrator_role->removed );
		self::assertCount( 4, $GLOBALS['wcip_unscheduled_actions'] );
	}

	public function test_opted_in_uninstall_removes_only_plugin_table_and_options(): void {
		$GLOBALS['wcip_test_options'] = array( 'wcip_delete_data_on_uninstall' => true, 'wcip_settings' => array(), 'wcip_db_version' => '1.0.0', 'woocommerce_currency' => 'USD' );
		defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', 'wc-invoice-printer.php' );
		require WCIP_PATH . 'uninstall.php';
		self::assertSame( array_map( static fn( $table ) => 'DROP TABLE IF EXISTS wp_' . $table, array( 'wc_invoice_print_jobs', 'wcip_runtime_leases', 'wcip_document_references', 'wcip_fulfillment', 'wcip_fulfillment_events', 'wcip_export_outbox' ) ), $GLOBALS['wpdb']->queries );
		self::assertSame( array( 'woocommerce_currency' => 'USD' ), $GLOBALS['wcip_test_options'] );
	}
}
