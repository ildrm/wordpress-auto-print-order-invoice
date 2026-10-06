<?php

namespace WCInvoicePrinter\Tests\Support;

use PHPUnit\Framework\TestCase;
use WCInvoicePrinter\Automation\Scheduler;
use WCInvoicePrinter\PrintJob\PrintJobRepository;
use WCInvoicePrinter\PrintJob\PrintJobService;
use WCInvoicePrinter\Settings\SettingsRepository;
use WCInvoicePrinter\Template\TemplateRegistry;

abstract class JobTestCase extends TestCase {
	protected InMemoryWpdb $db;
	protected PrintJobRepository $jobs;
	protected Scheduler $scheduler;
	protected SettingsRepository $settings;
	protected TemplateRegistry $templates;
	protected PrintJobService $service;

	protected function setUp(): void {
		$this->db = new InMemoryWpdb();
		$GLOBALS['wpdb'] = $this->db;
		$GLOBALS['wcip_test_options'] = array( 'wcip_settings' => array( 'automatic_enabled' => true, 'printnode_api_key' => 'test-key', 'printnode_printer_id' => '7' ) );
		$GLOBALS['wcip_test_orders'] = array();
		$GLOBALS['wcip_test_filters'] = array();
		$GLOBALS['wcip_test_hooks'] = array();
		$GLOBALS['wcip_test_actions'] = array();
		foreach ( array( 'wcip_existing_action_id', 'wcip_existing_action_status', 'wcip_scheduled_action', 'wcip_async_action_result', 'wcip_single_action_result', 'wcip_async_action_exception', 'wcip_single_action_exception' ) as $key ) { unset( $GLOBALS[ $key ] ); }
		\ActionScheduler::$initialized = true;
		$this->jobs = new PrintJobRepository();
		$this->scheduler = new Scheduler( $this->jobs );
		$this->settings = new SettingsRepository();
		$this->templates = new TemplateRegistry();
		$this->service = new PrintJobService( $this->jobs, $this->scheduler, $this->settings, $this->templates );
	}

	protected function tearDown(): void {
		$GLOBALS['wcip_test_filters'] = array();
		$GLOBALS['wcip_test_hooks'] = array();
		\ActionScheduler::$initialized = true;
		foreach ( array( 'wcip_existing_action_id', 'wcip_existing_action_status', 'wcip_async_action_result', 'wcip_single_action_result', 'wcip_async_action_exception', 'wcip_single_action_exception' ) as $key ) { unset( $GLOBALS[ $key ] ); }
	}

	protected function job( array $changes = array() ): array {
		return $this->jobs->create( array_merge( array( 'order_id' => 42, 'trigger_type' => 'automatic', 'idempotency_key' => hash( 'sha256', 'job-' . count( $this->db->rows ) ), 'template_id' => 'classic', 'provider_id' => 'printnode', 'printer_id' => '7', 'copies' => 1 ), $changes ) );
	}
}
