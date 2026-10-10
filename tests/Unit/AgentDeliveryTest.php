<?php

namespace WCInvoicePrinter\Tests\Unit;

use WCInvoicePrinter\Printing\Agent\AgentQueue;
use WCInvoicePrinter\Tests\Support\JobTestCase;

final class AgentDeliveryTest extends JobTestCase {
	private string $token;
	protected function setUp(): void { parent::setUp(); $this->token = str_repeat( 'a', 64 ); $this->settings->update( array( 'automatic_provider' => 'agent', 'agent_queue' => 'Office', 'agent_user_id' => 42, 'cups_endpoint' => '' ) ); }
	public function test_pull_jobs_require_no_cups_or_background_scheduler_and_deduplicate_payments(): void {
		\ActionScheduler::$initialized = false;
		$first = $this->service->create_automatic( new \WC_Order( 42 ) );
		$second = $this->service->create_automatic( new \WC_Order( 42 ) );
		self::assertSame( 'agent', $first['provider_id'] ); self::assertSame( 'Office', $first['printer_id'] );
		self::assertSame( 'queued', $first['status'] ); self::assertNull( $first['action_id'] ); self::assertSame( $first['id'], $second['id'] );
		self::assertSame( 0, $this->scheduler->enqueue( $first['id'] ) );
	}
	public function test_manual_agent_route_cannot_choose_an_unpaired_printer(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->service->create_manual( new \WC_Order( 42 ), 'classic', 'agent', 'Another', 1 );
	}
	public function test_atomic_claim_and_start_are_bound_to_user_and_token(): void {
		$job = $this->service->create_automatic( new \WC_Order( 42 ) ); $q = new AgentQueue();
		self::assertTrue( $q->claim( $job['id'], 'Office', 42, $this->token ) );
		self::assertFalse( $q->claim( $job['id'], 'Office', 42, $this->token ) );
		self::assertSame( hash( 'sha256', $this->token ), $this->jobs->find( $job['id'] )['agent_token'] );
		self::assertFalse( $q->start( $job['id'], 7, $this->token ) );
		self::assertFalse( $q->start( $job['id'], 42, str_repeat( 'b', 64 ) ) );
		self::assertFalse( $q->receipt( $job['id'], 42, $this->token, 'submitted' ) );
		self::assertTrue( $q->start( $job['id'], 42, $this->token ) );
		self::assertFalse( $q->start( $job['id'], 42, $this->token ) );
		self::assertTrue( $q->receipt( $job['id'], 42, $this->token, 'submitted' ) );
		self::assertNull( $this->jobs->find( $job['id'] )['printed_at'] );
	}
	public function test_stale_preparation_failure_cannot_overwrite_another_claim(): void {
		$job = $this->service->create_automatic( new \WC_Order( 42 ) ); $q = new AgentQueue(); $q->claim( $job['id'], 'Office', 42, $this->token );
		self::assertFalse( $q->reject_prepared( $job['id'], 42, str_repeat( 'b', 64 ) ) );
		self::assertSame( 'processing', $this->jobs->find( $job['id'] )['status'] );
		self::assertTrue( $q->reject_prepared( $job['id'], 42, $this->token ) );
		self::assertSame( 'failed', $this->jobs->find( $job['id'] )['status'] );
	}
	public function test_agent_auth_requires_tls_application_password_paired_identity_and_capability(): void {
		$c = new \WCInvoicePrinter\Rest\AgentController( $this->settings, $this->jobs, null, null, null, null, null );
		$c->register();
		$GLOBALS['wcip_test_user_id'] = 42; $GLOBALS['wcip_test_capabilities']['wcip_run_print_agent'] = true;
		self::assertFalse( $c->allowed() );
		$GLOBALS['wcip_test_ssl'] = true; self::assertFalse( $c->allowed() );
		$GLOBALS['wcip_test_app_password'] = 'uuid'; self::assertTrue( $c->allowed() );
		$GLOBALS['wcip_test_user_id'] = 7; self::assertFalse( $c->allowed() );
		$GLOBALS['wcip_test_user_id'] = 42; $GLOBALS['wcip_test_capabilities']['wcip_run_print_agent'] = false; self::assertFalse( $c->allowed() );
		unset( $GLOBALS['wcip_test_ssl'], $GLOBALS['wcip_test_app_password'], $GLOBALS['wcip_test_user_id'] );
	}
}
