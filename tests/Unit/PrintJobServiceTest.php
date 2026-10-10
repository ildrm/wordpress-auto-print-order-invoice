<?php

namespace WCInvoicePrinter\Tests\Unit;

use WCInvoicePrinter\PrintJob\IdempotencyKey;
use WCInvoicePrinter\Tests\Support\JobTestCase;

final class PrintJobServiceTest extends JobTestCase {
	public function test_unpaid_and_disabled_orders_never_create_jobs(): void {
		self::assertNull( $this->service->create_automatic( new \WC_Order( 42, 'pending' ) ) );
		$this->settings->update( array( 'automatic_enabled' => false ) );
		self::assertNull( $this->service->create_automatic( new \WC_Order( 42 ) ) );
		self::assertCount( 0, $this->db->rows );
	}

	public function test_missing_credentials_invalid_printer_template_and_filter_stop_automatic_printing(): void {
		foreach ( array( array( 'cups_endpoint' => '' ), array( 'cups_printer_id' => '../bad' ), array( 'cups_printer_id' => '-2' ), array( 'automatic_template' => 'missing' ) ) as $changes ) {
			$before = $GLOBALS['wcip_test_options']['wcip_settings'];
			$this->settings->update( $changes );
			self::assertNull( $this->service->create_automatic( new \WC_Order( 42 ) ) );
			$GLOBALS['wcip_test_options']['wcip_settings'] = $before;
		}
		$GLOBALS['wcip_test_filters']['wcip_automatic_print_eligible'][] = static fn() => false;
		self::assertNull( $this->service->create_automatic( new \WC_Order( 42 ) ) );
		self::assertCount( 0, $this->db->rows );
	}

	public function test_payment_callbacks_and_transaction_updates_share_one_job(): void {
		$order = new \WC_Order( 42, 'processing', '' );
		$first = $this->service->create_automatic( $order );
		$order->set_transaction_id( 'later-id' );
		$second = $this->service->create_automatic( $order );
		self::assertSame( $first['id'], $second['id'] );
		self::assertSame( 101, $second['action_id'] );
		self::assertCount( 1, $this->db->rows );
	}

	public function test_existing_orphaned_queued_job_is_scheduled_on_a_repeated_callback(): void {
		$job = $this->job( array( 'idempotency_key' => IdempotencyKey::automatic( 42, '' ) ) );
		$result = $this->service->create_automatic( new \WC_Order( 42 ) );
		self::assertSame( $job['id'], $result['id'] );
		self::assertSame( 101, $result['action_id'] );
	}

	public function test_legacy_automatic_keys_remain_idempotent_after_an_upgrade(): void {
		$job = $this->job( array( 'idempotency_key' => hash( 'sha256', 'legacy:42:old-transaction' ) ) );
		$this->jobs->claim( $job['id'] );
		$this->jobs->submitted( $job['id'], '123' );
		$result = $this->service->create_automatic( new \WC_Order( 42, 'processing', 'new-transaction' ) );
		self::assertSame( $job['id'], $result['id'] );
		self::assertSame( 'submitted', $result['status'] );
		self::assertCount( 1, $this->db->rows );
		self::assertArrayNotHasKey( 'wcip_scheduled_action', $GLOBALS );
	}

	public function test_concurrent_unique_insert_returns_and_schedules_only_the_winning_job(): void {
		$this->db->before_insert = static function ( $db, $data ): void { $db->insert( 'wp_wc_invoice_print_jobs', $data ); };
		$job = $this->service->create_automatic( new \WC_Order( 42 ) );
		self::assertCount( 1, $this->db->rows );
		self::assertSame( 1, $job['id'] );
		self::assertSame( 101, $job['action_id'] );
	}

	public function test_manual_jobs_are_unique_and_browser_jobs_do_not_need_credentials(): void {
		$this->settings->update( array( 'cups_endpoint' => '' ) );
		$first = $this->service->create_manual( new \WC_Order( 42, 'pending' ), 'classic', 'browser', '', 2 );
		$second = $this->service->create_manual( new \WC_Order( 42, 'pending' ), 'classic', 'browser', '', 2 );
		self::assertNotSame( $first['idempotency_key'], $second['idempotency_key'] );
		self::assertNull( $first['action_id'] );
		self::assertArrayNotHasKey( 'wcip_scheduled_action', $GLOBALS );
	}

	public function test_manual_print_requires_configuration_and_valid_copy_count(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->service->create_manual( new \WC_Order( 42 ), 'classic', 'cups', '../bad', 1 );
	}

	public function test_manual_print_rejects_missing_credentials(): void {
		$this->settings->update( array( 'cups_endpoint' => '' ) );
		$this->expectException( \InvalidArgumentException::class );
		$this->service->create_manual( new \WC_Order( 42 ), 'classic', 'cups', '7', 1 );
	}

	public function test_manual_print_rejects_invalid_copy_count(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->service->create_manual( new \WC_Order( 42 ), 'classic', 'browser', '', -3 );
	}

	public function test_manual_service_returns_the_actual_scheduler_failure_state(): void {
		$GLOBALS['wcip_async_action_result'] = 0;
		$job = $this->service->create_manual( new \WC_Order( 42 ), 'classic', 'cups', '7', 1 );
		self::assertSame( 'failed', $job['status'] );
		self::assertSame( 'schedule_failed', $job['error_code'] );
	}
}
