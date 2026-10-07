<?php

namespace WCInvoicePrinter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCInvoicePrinter\PrintJob\PrintJobRepository;
use WCInvoicePrinter\PrintJob\PrintConfirmationService;
use WCInvoicePrinter\Tests\Support\InMemoryWpdb;

final class PrintConfirmationTest extends TestCase {
	private InMemoryWpdb $database;
	private PrintJobRepository $jobs;
	private PrintConfirmationService $service;

	protected function setUp(): void {
		$this->database = new InMemoryWpdb();
		$GLOBALS['wpdb'] = $this->database;
		$GLOBALS['wcip_test_orders'] = array( 1 => new \WC_Order( 1 ) );
		$GLOBALS['wcip_test_notes'] = array();
		$GLOBALS['wcip_note_failure'] = false;
		$this->jobs = new PrintJobRepository();
		$this->service = new PrintConfirmationService( $this->jobs );
	}

	protected function tearDown(): void { unset( $GLOBALS['wcip_note_failure'], $GLOBALS['wcip_test_notes'] ); }

	private function job( string $status = 'submitted' ): int {
		$job = $this->jobs->create( array( 'order_id' => 1, 'trigger_type' => 'manual', 'idempotency_key' => wp_generate_uuid4(), 'template_id' => 'classic', 'provider_id' => 'browser', 'printer_id' => '', 'copies' => 2 ) );
		$this->database->rows[ $job['id'] ]['status'] = $status;
		return (int) $job['id'];
	}

	public function test_confirmation_adds_one_private_note_and_preserves_job_dates_and_state(): void {
		$id = $this->job();
		$before = $this->jobs->find( $id );
		$result = $this->service->confirm( $id );
		self::assertSame( 42, $result['printed_by'] );
		self::assertNotEmpty( $result['printed_at'] );
		self::assertSame( $before['created_at'], $result['created_at'] );
		self::assertSame( $before['updated_at'], $result['updated_at'] );
		self::assertSame( 'submitted', $result['status'] );
		$note = $GLOBALS['wcip_test_notes'][ $result['printed_note_id'] ];
		self::assertFalse( $note['customer'] );
		self::assertTrue( $note['by_user'] );
		self::assertStringContainsString( 'Invoice printed.', $note['note'] );
		self::assertSame( $result, $this->service->confirm( $id ) );
		self::assertCount( 1, $GLOBALS['wcip_test_notes'] );
		self::assertSame( $id, (int) $this->jobs->latest_confirmed_for_order( 1 )['id'] );
	}

	/** @dataProvider unconfirmable_states */
	public function test_unfinished_or_failed_jobs_cannot_be_marked_printed( string $status ): void {
		$id = $this->job( $status );
		try { $this->service->confirm( $id ); self::fail( 'Unexpected confirmation.' ); }
		catch ( \RuntimeException $error ) { self::assertSame( 409, $error->getCode() ); }
		self::assertSame( array(), $GLOBALS['wcip_test_notes'] );
		self::assertNull( $this->jobs->latest_confirmed_for_order( 1 ) );
	}

	public static function unconfirmable_states(): array { return array( array( 'queued' ), array( 'processing' ), array( 'failed' ), array( 'cancelled' ) ); }

	public function test_operator_can_confirm_paper_output_after_ambiguous_submission(): void {
		$result = $this->service->confirm( $this->job( 'unknown' ) );
		self::assertSame( 'unknown', $result['status'] );
		self::assertNotEmpty( $result['printed_at'] );
	}

	public function test_failure_to_save_confirmation_removes_note_and_rolls_back(): void {
		$id = $this->job();
		$this->database->fail_update = true;
		try { $this->service->confirm( $id ); self::fail( 'Unexpected confirmation.' ); }
		catch ( \RuntimeException $error ) { self::assertStringContainsString( 'could not be saved', $error->getMessage() ); }
		self::assertSame( array(), $GLOBALS['wcip_test_notes'] );
		self::assertNull( $this->jobs->find( $id )['printed_at'] );
	}

	public function test_note_failure_does_not_mark_printed(): void {
		$id = $this->job();
		$GLOBALS['wcip_note_failure'] = true;
		try { $this->service->confirm( $id ); self::fail( 'Unexpected confirmation.' ); }
		catch ( \RuntimeException $error ) { self::assertStringContainsString( 'could not be saved', $error->getMessage() ); }
		self::assertNull( $this->jobs->find( $id )['printed_at'] );
	}

	public function test_reprint_failure_does_not_erase_previous_confirmation(): void {
		$id = $this->job();
		$this->service->confirm( $id );
		$this->job( 'failed' );
		self::assertSame( $id, (int) $this->jobs->latest_confirmed_for_order( 1 )['id'] );
	}
}
