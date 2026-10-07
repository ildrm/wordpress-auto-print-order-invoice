<?php

namespace WCInvoicePrinter\Tests\Unit;

use WCInvoicePrinter\Automation\PrintWorker;
use WCInvoicePrinter\Invoice\InvoiceFactory;
use WCInvoicePrinter\Pdf\PdfRendererInterface;
use WCInvoicePrinter\Printing\PrintProviderInterface;
use WCInvoicePrinter\Printing\PrintProviderRegistry;
use WCInvoicePrinter\Printing\ProviderException;
use WCInvoicePrinter\Printing\RetryPolicy;
use WCInvoicePrinter\Printing\SubmissionResult;
use WCInvoicePrinter\Template\HtmlRenderer;
use WCInvoicePrinter\Template\TemplateDefinition;
use WCInvoicePrinter\Tests\Support\JobTestCase;

final class PrintWorkerTest extends JobTestCase {
	private object $provider;
	private object $pdf;
	private PrintWorker $worker;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wcip_test_orders'][42] = new \WC_Order( 42 );
		$this->provider = new class() implements PrintProviderInterface {
			public array $submissions = array();
			public ?\Throwable $exception = null;
			public $callback = null;
			public function id(): string { return 'printnode'; }
			public function test_connection(): array { return array(); }
			public function printers( bool $force_refresh = false ): array { return array(); }
			public function submit( string $pdf_bytes, string $printer_id, int $copies, string $title ): SubmissionResult {
				$this->submissions[] = compact( 'pdf_bytes', 'printer_id', 'copies', 'title' );
				if ( is_callable( $this->callback ) ) { ( $this->callback )(); }
				if ( $this->exception ) { throw $this->exception; }
				return new SubmissionResult( '987' );
			}
		};
		$this->pdf = new class() implements PdfRendererInterface {
			public ?\Throwable $exception = null;
			public function render( string $html, TemplateDefinition $template ): string {
				if ( $this->exception ) { throw $this->exception; }
				return '%PDF-test';
			}
		};
		$providers = new PrintProviderRegistry();
		$providers->register( $this->provider );
		$this->worker = new PrintWorker( $this->jobs, new InvoiceFactory( $this->settings ), new HtmlRenderer( $this->templates ), $this->pdf, $this->templates, $providers, new RetryPolicy(), $this->scheduler );
	}

	public function test_successful_submission_is_persisted_and_duplicate_execution_cannot_print_again(): void {
		$job = $this->job( array( 'copies' => 2 ) );
		$this->worker->process( $job['id'] );
		$this->worker->process( $job['id'] );
		self::assertCount( 1, $this->provider->submissions );
		self::assertSame( array( 'pdf_bytes' => '%PDF-test', 'printer_id' => '7', 'copies' => 2, 'title' => 'Invoice 42' ), $this->provider->submissions[0] );
		$row = $this->jobs->find( $job['id'] );
		self::assertSame( 'submitted', $row['status'] );
		self::assertSame( '987', $row['external_job_id'] );
		self::assertSame( 1, $row['attempt_count'] );
	}

	public function test_order_becoming_unpaid_stops_automatic_but_allows_intentional_manual_print(): void {
		$GLOBALS['wcip_test_orders'][42]->set_status( 'refunded' );
		$automatic = $this->job();
		$this->worker->process( $automatic['id'] );
		self::assertSame( 'failed', $this->jobs->find( $automatic['id'] )['status'] );
		self::assertCount( 0, $this->provider->submissions );
		$manual = $this->job( array( 'trigger_type' => 'manual' ) );
		$this->worker->process( $manual['id'] );
		self::assertSame( 'submitted', $this->jobs->find( $manual['id'] )['status'] );
		self::assertCount( 1, $this->provider->submissions );
	}

	public function test_deleted_order_and_generation_errors_never_contact_the_provider(): void {
		$missing = $this->job( array( 'order_id' => 999 ) );
		$this->worker->process( $missing['id'] );
		self::assertSame( 'generation_failed', $this->jobs->find( $missing['id'] )['error_code'] );
		$this->pdf->exception = new \RuntimeException( 'PDF could not be generated' );
		$job = $this->job();
		$this->worker->process( $job['id'] );
		self::assertSame( 'failed', $this->jobs->find( $job['id'] )['status'] );
		self::assertCount( 0, $this->provider->submissions );
	}

	public function test_safe_rejections_retry_at_most_three_times(): void {
		$this->provider->exception = new ProviderException( 'Rate limited', 'http_429', true, false );
		$job = $this->job();
		$this->worker->process( $job['id'] );
		self::assertSame( 'queued', $this->jobs->find( $job['id'] )['status'] );
		self::assertSame( 102, $this->jobs->find( $job['id'] )['action_id'] );
		self::assertFalse( $GLOBALS['wcip_scheduled_action']['unique'] );
		self::assertGreaterThanOrEqual( time() + 59, $GLOBALS['wcip_scheduled_action']['timestamp'] );
		$this->worker->process( $job['id'] );
		self::assertGreaterThanOrEqual( time() + 119, $GLOBALS['wcip_scheduled_action']['timestamp'] );
		$this->worker->process( $job['id'] );
		$this->worker->process( $job['id'] );
		self::assertSame( 'failed', $this->jobs->find( $job['id'] )['status'] );
		self::assertSame( 3, $this->jobs->find( $job['id'] )['attempt_count'] );
		self::assertCount( 3, $this->provider->submissions );
		self::assertFalse( $this->jobs->retry_failed( $job['id'] ) );
	}

	public function test_ambiguous_provider_failure_is_unknown_and_never_retried(): void {
		$this->provider->exception = new ProviderException( 'May have been accepted', 'transport_unknown', true, true );
		$job = $this->job();
		$this->worker->process( $job['id'] );
		self::assertSame( 'unknown', $this->jobs->find( $job['id'] )['status'] );
		self::assertFalse( $this->jobs->retry_failed( $job['id'] ) );
		self::assertArrayNotHasKey( 'wcip_scheduled_action', $GLOBALS );
	}

	public function test_unexpected_error_inside_submission_is_also_unknown(): void {
		$this->provider->exception = new \RuntimeException( 'Provider implementation crashed after sending' );
		$job = $this->job();
		$this->worker->process( $job['id'] );
		self::assertSame( 'unknown', $this->jobs->find( $job['id'] )['status'] );
		self::assertSame( 'submission_unknown', $this->jobs->find( $job['id'] )['error_code'] );
		self::assertFalse( $this->jobs->retry_failed( $job['id'] ) );
	}

	public function test_definitive_non_retryable_rejection_is_failed(): void {
		$this->provider->exception = new ProviderException( 'Bad API key', 'http_401', false, false );
		$job = $this->job();
		$this->worker->process( $job['id'] );
		self::assertSame( 'failed', $this->jobs->find( $job['id'] )['status'] );
		self::assertSame( 'http_401', $this->jobs->find( $job['id'] )['error_code'] );
	}

	public function test_exception_in_pre_submission_observer_is_a_generation_failure(): void {
		$GLOBALS['wcip_test_hooks']['wcip_before_print_submission'][] = static function (): void { throw new \RuntimeException( 'Observer failed' ); };
		$job = $this->job();
		$this->worker->process( $job['id'] );
		self::assertSame( 'generation_failed', $this->jobs->find( $job['id'] )['error_code'] );
		self::assertCount( 0, $this->provider->submissions );
	}

	public function test_refund_while_rendering_is_detected_before_automatic_submission(): void {
		$GLOBALS['wcip_test_hooks']['wcip_before_print_submission'][] = static function (): void { $GLOBALS['wcip_test_orders'][42] = new \WC_Order( 42, 'refunded' ); };
		$job = $this->job();
		$this->worker->process( $job['id'] );
		self::assertSame( 'failed', $this->jobs->find( $job['id'] )['status'] );
		self::assertSame( 'generation_failed', $this->jobs->find( $job['id'] )['error_code'] );
		self::assertCount( 0, $this->provider->submissions );
	}

	public function test_exception_in_post_submission_observer_preserves_the_successful_job(): void {
		$GLOBALS['wcip_test_hooks']['wcip_after_print_submission'][] = static function (): void { throw new \RuntimeException( 'Observer failed' ); };
		$job = $this->job();
		try {
			$this->worker->process( $job['id'] );
			self::fail( 'The action runner should receive the observer failure.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'Observer failed', $exception->getMessage() );
		}
		self::assertSame( 'submitted', $this->jobs->find( $job['id'] )['status'] );
		self::assertCount( 1, $this->provider->submissions );
		self::assertFalse( $this->jobs->retry_failed( $job['id'] ) );
	}

	public function test_interrupted_action_marks_only_an_unfinished_processing_job_unknown(): void {
		$job = $this->job();
		$this->jobs->set_action_id( $job['id'], 55 );
		$this->worker->interrupted( 55 );
		self::assertSame( 'queued', $this->jobs->find( $job['id'] )['status'] );
		$this->jobs->claim( $job['id'] );
		$this->worker->interrupted( 55 );
		self::assertSame( 'unknown', $this->jobs->find( $job['id'] )['status'] );
		self::assertSame( 'worker_interrupted', $this->jobs->find( $job['id'] )['error_code'] );
		$completed = $this->job();
		$this->jobs->set_action_id( $completed['id'], 66 );
		$this->worker->process( $completed['id'] );
		$this->worker->interrupted( 66 );
		self::assertSame( 'submitted', $this->jobs->find( $completed['id'] )['status'] );
	}

	public function test_retry_queue_failure_is_failed_before_a_second_submission(): void {
		$GLOBALS['wcip_single_action_exception'] = new \RuntimeException( 'queue storage unavailable' );
		$this->provider->exception = new ProviderException( 'Rate limited', 'http_429', true, false );
		$job = $this->job();
		$this->worker->process( $job['id'] );
		self::assertSame( 'failed', $this->jobs->find( $job['id'] )['status'] );
		self::assertSame( 'schedule_failed', $this->jobs->find( $job['id'] )['error_code'] );
		self::assertCount( 1, $this->provider->submissions );
	}

	public function test_provider_acceptance_followed_by_database_failure_cannot_be_resubmitted(): void {
		$job = $this->job();
		$this->jobs->set_action_id( $job['id'], 77 );
		$this->provider->callback = function (): void { $this->db->fail_update = true; };
		$this->worker->process( $job['id'] );
		self::assertSame( 'processing', $this->jobs->find( $job['id'] )['status'] );
		self::assertCount( 1, $this->provider->submissions );
		$this->db->fail_update = false;
		$this->db->rows[ $job['id'] ]['started_at'] = gmdate( 'Y-m-d H:i:s', time() - 601 );
		$this->scheduler->recover();
		self::assertSame( 'unknown', $this->jobs->find( $job['id'] )['status'] );
		$this->worker->process( $job['id'] );
		self::assertCount( 1, $this->provider->submissions );
		self::assertArrayNotHasKey( 'wcip_scheduled_action', $GLOBALS );
	}
}
