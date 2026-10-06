<?php

namespace WCInvoicePrinter\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WCInvoicePrinter\Automation\Scheduler;
use WCInvoicePrinter\Invoice\InvoiceFactory;
use WCInvoicePrinter\Pdf\PdfRendererInterface;
use WCInvoicePrinter\Printing\PrintProviderInterface;
use WCInvoicePrinter\Printing\PrintProviderRegistry;
use WCInvoicePrinter\PrintJob\PrintJobRepository;
use WCInvoicePrinter\PrintJob\PrintJobService;
use WCInvoicePrinter\Rest\RestController;
use WCInvoicePrinter\Settings\SettingsRepository;
use WCInvoicePrinter\Template\HtmlRenderer;
use WCInvoicePrinter\Template\TemplateRegistry;
use WCInvoicePrinter\Tests\Support\InMemoryWpdb;

final class RestControllerTest extends TestCase {
	private InMemoryWpdb $database;
	private RestController $controller;
	private SettingsRepository $settings;
	private PrintProviderRegistry $providers;

	protected function setUp(): void {
		$this->database = new InMemoryWpdb();
		$GLOBALS['wpdb'] = $this->database;
		$GLOBALS['wcip_test_options'] = array( 'wcip_settings' => array( 'printnode_api_key' => 'secret', 'printnode_printer_id' => '123' ) );
		$GLOBALS['wcip_test_capabilities'] = array();
		$GLOBALS['wcip_test_routes'] = array();
		$GLOBALS['wcip_test_orders'] = array( 1 => new \WC_Order( 1 ), 2 => new \WC_Order( 2 ) );
		unset( $GLOBALS['wcip_existing_action_id'], $GLOBALS['wcip_scheduled_action'], $GLOBALS['wcip_async_action_result'], $GLOBALS['wcip_async_action_exception'] );
		\ActionScheduler::$initialized = true;
		$this->settings = new SettingsRepository();
		$this->providers = new PrintProviderRegistry();
		$templates = new TemplateRegistry();
		$jobs = new PrintJobRepository();
		$scheduler = new Scheduler( $jobs );
		$this->controller = new RestController( $this->settings, $this->providers, new PrintJobService( $jobs, $scheduler, $this->settings, $templates ), $jobs, $scheduler, new InvoiceFactory( $this->settings ), new HtmlRenderer( $templates ), $this->createMock( PdfRendererInterface::class ), $templates );
	}

	protected function tearDown(): void { \ActionScheduler::$initialized = true; }

	private function request( array $changes = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST' );
		$request->set_body_params( array_merge( array( 'order_ids' => array( 1 ), 'template_id' => 'classic', 'provider_id' => 'printnode', 'printer_id' => '123', 'copies' => 1 ), $changes ) );
		return $request;
	}

	public function test_invalid_later_order_does_not_queue_earlier_order(): void {
		$response = $this->controller->manual_print( $this->request( array( 'order_ids' => array( 1, 999 ) ) ) );
		self::assertInstanceOf( \WP_Error::class, $response );
		self::assertSame( 404, $response->get_error_data()['status'] );
		self::assertSame( array(), $this->database->rows );
		self::assertArrayNotHasKey( 'wcip_scheduled_action', $GLOBALS );
	}

	#[DataProvider( 'invalid_print_requests' )]
	public function test_invalid_print_request_has_no_side_effects( array $changes ): void {
		$response = $this->controller->manual_print( $this->request( $changes ) );
		self::assertInstanceOf( \WP_Error::class, $response );
		self::assertSame( 400, $response->get_error_data()['status'] );
		self::assertSame( array(), $this->database->rows );
	}

	public static function invalid_print_requests(): array {
		return array( array( array( 'order_ids' => array() ) ), array( array( 'order_ids' => '1' ) ), array( array( 'order_ids' => array_fill( 0, 51, 1 ) ) ), array( array( 'order_ids' => array( -1 ) ) ), array( array( 'order_ids' => array( 1.5 ) ) ), array( array( 'template_id' => 'missing' ) ), array( array( 'template_id' => array() ) ), array( array( 'provider_id' => 'browser' ) ), array( array( 'printer_id' => '0' ) ), array( array( 'printer_id' => '-123' ) ), array( array( 'copies' => 0 ) ), array( array( 'copies' => 21 ) ), array( array( 'copies' => 1.5 ) ) );
	}

	public function test_draft_and_trashed_orders_cannot_be_printed(): void {
		foreach ( array( 'checkout-draft', 'trash' ) as $status ) {
			$GLOBALS['wcip_test_orders'][1]->set_status( $status );
			$response = $this->controller->manual_print( $this->request() );
			self::assertInstanceOf( \WP_Error::class, $response );
			self::assertSame( 404, $response->get_error_data()['status'] );
		}
		self::assertSame( array(), $this->database->rows );
	}

	public function test_manual_selection_deduplicates_orders_and_returns_job_ids(): void {
		$response = $this->controller->manual_print( $this->request( array( 'order_ids' => array( 1, 1, 2 ) ) ) );
		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertSame( 201, $response->get_status() );
		self::assertSame( array( 'queued' => 2, 'job_ids' => array( 1, 2 ) ), $response->get_data() );
		self::assertCount( 2, $this->database->rows );
		self::assertSame( 'queued', $this->database->rows[1]['status'] );
		self::assertSame( 101, $this->database->rows[2]['action_id'] );
	}

	public function test_missing_credentials_do_not_create_jobs(): void {
		$this->settings->update( array( 'printnode_api_key' => '' ) );
		$response = $this->controller->manual_print( $this->request() );
		self::assertInstanceOf( \WP_Error::class, $response );
		self::assertSame( 'wcip_not_configured', $response->get_error_code() );
		self::assertSame( array(), $this->database->rows );
	}

	public function test_scheduler_failure_is_reported_instead_of_claiming_queued(): void {
		\ActionScheduler::$initialized = false;
		$response = $this->controller->manual_print( $this->request() );
		self::assertInstanceOf( \WP_Error::class, $response );
		self::assertSame( 503, $response->get_error_data()['status'] );
		self::assertSame( array( 1 ), $response->get_error_data()['job_ids'] );
		self::assertSame( 'failed', $this->database->rows[1]['status'] );
	}

	public function test_partial_database_failure_reports_created_jobs_without_raw_error(): void {
		$this->database->before_update = static function ( InMemoryWpdb $database ): void { $database->fail_insert = true; };
		$response = $this->controller->manual_print( $this->request( array( 'order_ids' => array( 1, 2 ) ) ) );
		self::assertInstanceOf( \WP_Error::class, $response );
		self::assertSame( 500, $response->get_error_data()['status'] );
		self::assertSame( array( 1 ), $response->get_error_data()['job_ids'] );
		self::assertStringContainsString( 'Review Print Jobs', $response->get_error_message() );
	}

	public function test_read_only_job_capability_does_not_allow_cancel_or_retry(): void {
		$GLOBALS['wcip_test_capabilities']['wcip_view_print_jobs'] = true;
		$this->controller->register();
		foreach ( array( 'cancel', 'retry' ) as $action ) {
			$route = $GLOBALS['wcip_test_routes'][ 'wc-invoice-printer/v1/jobs/(?P<id>\\d+)/' . $action ];
			self::assertFalse( call_user_func( $route['permission_callback'] ) );
		}
		$GLOBALS['wcip_test_capabilities']['wcip_print_invoices'] = true;
		self::assertTrue( $this->controller->can_manage_jobs() );
		self::assertFalse( $this->controller->can_manage() );
	}

	public function test_retry_missing_job_returns_not_found(): void {
		$request = new \WP_REST_Request( 'POST' );
		$request['id'] = 999;
		$response = $this->controller->retry_job( $request );
		self::assertInstanceOf( \WP_Error::class, $response );
		self::assertSame( 404, $response->get_error_data()['status'] );
	}

	public function test_unexpected_provider_exception_does_not_expose_internals(): void {
		$provider = $this->createMock( PrintProviderInterface::class );
		$provider->method( 'id' )->willReturn( 'printnode' );
		$provider->method( 'test_connection' )->willThrowException( new \RuntimeException( 'secret password /private/path' ) );
		$this->providers->register( $provider );
		$response = $this->controller->test_connection();
		self::assertInstanceOf( \WP_Error::class, $response );
		self::assertStringNotContainsString( 'secret', $response->get_error_message() );
		self::assertStringNotContainsString( '/private', $response->get_error_message() );
	}
}
