<?php

namespace WCInvoicePrinter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCInvoicePrinter\Printing\PrintNode\PrintNodeProvider;
use WCInvoicePrinter\Printing\ProviderException;
use WCInvoicePrinter\Settings\SettingsRepository;

final class PrintNodeProviderTest extends TestCase {
	private PrintNodeProvider $provider;

	protected function setUp(): void {
		$GLOBALS['wcip_test_options']['wcip_settings'] = array( 'printnode_api_key' => 'server-secret' );
		$GLOBALS['wcip_test_transients'] = array();
		$GLOBALS['wcip_test_filters'] = array();
		unset( $GLOBALS['wcip_http_callback'], $GLOBALS['wcip_last_http'] );
		$this->provider = new PrintNodeProvider( new SettingsRepository() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wcip_http_callback'] );
	}

	public function test_submission_normalizes_external_job_id_and_uses_base64(): void {
		$GLOBALS['wcip_http_response'] = array( 'response' => array( 'code' => 201 ), 'body' => '623' );
		$result = $this->provider->submit( '%PDF-test', '34', 2, 'Invoice 42' );
		self::assertSame( '623', $result->external_job_id );
		$payload = json_decode( $GLOBALS['wcip_last_http'][1]['body'], true );
		self::assertSame( 'pdf_base64', $payload['contentType'] );
		self::assertSame( 2, $payload['qty'] );
		self::assertSame( '%PDF-test', base64_decode( $payload['content'], true ) );
		self::assertSame( 'https://api.printnode.com/printjobs', $GLOBALS['wcip_last_http'][0] );
		self::assertSame( 'Basic ' . base64_encode( 'server-secret:' ), $GLOBALS['wcip_last_http'][1]['headers']['Authorization'] );
		self::assertSame( 0, $GLOBALS['wcip_last_http'][1]['redirection'] );
	}

	public function test_transport_error_is_ambiguous_and_not_retryable(): void {
		$GLOBALS['wcip_http_response'] = new \WP_Error( 'timeout', 'secret-bearing raw transport message' );
		try { $this->provider->submit( 'pdf', '34', 1, 'Invoice' ); self::fail( 'Expected exception' ); } catch ( ProviderException $error ) { self::assertTrue( $error->ambiguous() ); self::assertFalse( $error->retryable() ); self::assertStringNotContainsString( 'secret-bearing', $error->getMessage() ); }
	}

	public function test_server_error_after_submission_is_ambiguous(): void {
		$GLOBALS['wcip_http_response'] = array( 'response' => array( 'code' => 503 ), 'body' => '{"secret":"not surfaced"}' );
		$error = $this->failure( fn() => $this->provider->submit( 'pdf', '34', 1, 'Invoice' ) );
		self::assertTrue( $error->ambiguous() );
		self::assertFalse( $error->retryable() );
		self::assertSame( 'http_503', $error->error_code() );
		self::assertStringNotContainsString( 'secret', $error->getMessage() );
	}

	public function test_invalid_printer_never_reaches_network(): void {
		$error = $this->failure( fn() => $this->provider->submit( 'pdf', '../34', 1, 'Invoice' ) );
		self::assertSame( 'invalid_printer', $error->error_code() );
		self::assertArrayNotHasKey( 'wcip_last_http', $GLOBALS );
	}

	/**
	 * @dataProvider malformed_jobs
	 */
	public function test_malformed_success_never_implies_a_safe_reprint( string $body ): void {
		$GLOBALS['wcip_http_response'] = array( 'response' => array( 'code' => 201 ), 'body' => $body );
		$error = $this->failure( fn() => $this->provider->submit( 'pdf', '34', 1, 'Invoice' ) );
		self::assertSame( 'malformed_response', $error->error_code() );
		self::assertTrue( $error->ambiguous() );
		self::assertFalse( $error->retryable() );
	}

	public static function malformed_jobs(): array {
		return array_map( static fn( $value ) => array( $value ), array( 'true', 'false', 'null', '0', '-1', '623.0', '"0"', '" 623"', '"+623"', '"0623"', '"92233720368547758080"', '[]', '{}', '"secret raw body"', '<html>upstream error</html>', '' ) );
	}

	/**
	 * @dataProvider submission_errors
	 */
	public function test_submission_http_error_classification( int $status, bool $ambiguous, bool $retryable ): void {
		$GLOBALS['wcip_http_response'] = array( 'response' => array( 'code' => $status ), 'body' => '{"message":"server-secret"}' );
		$error = $this->failure( fn() => $this->provider->submit( 'pdf', '34', 1, 'Invoice' ) );
		self::assertSame( $ambiguous, $error->ambiguous() );
		self::assertSame( $retryable, $error->retryable() );
		self::assertStringNotContainsString( 'server-secret', $error->getMessage() );
	}

	public static function submission_errors(): array {
		return array( array( 0, true, false ), array( 408, true, false ), array( 500, true, false ), array( 502, true, false ), array( 504, true, false ), array( 429, false, true ), array( 401, false, false ), array( 400, false, false ), array( 403, false, false ), array( 302, true, false ) );
	}

	public function test_read_only_transport_failure_has_no_ambiguous_print(): void {
		$GLOBALS['wcip_http_response'] = new \WP_Error( 'timeout', 'raw secret' );
		$error = $this->failure( fn() => $this->provider->test_connection() );
		self::assertFalse( $error->ambiguous() );
		self::assertTrue( $error->retryable() );
	}

	public function test_connection_requires_a_valid_account_record(): void {
		$GLOBALS['wcip_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"id":433,"firstname":"<b>Alex</b>"}' );
		self::assertSame( array( 'connected' => true, 'account' => 'Alex' ), $this->provider->test_connection() );
	}

	/**
	 * @dataProvider malformed_accounts
	 */
	public function test_invalid_account_never_reports_connected( string $body ): void {
		$GLOBALS['wcip_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => $body );
		$error = $this->failure( fn() => $this->provider->test_connection() );
		self::assertSame( 'malformed_response', $error->error_code() );
		self::assertFalse( $error->ambiguous() );
	}

	public static function malformed_accounts(): array {
		return array( array( 'null' ), array( '{}' ), array( '[]' ), array( 'true' ), array( '{"id":0}' ), array( '{"id":433,"firstname":[]}' ) );
	}

	public function test_printer_cache_is_scoped_to_the_effective_api_key(): void {
		$GLOBALS['wcip_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => '[{"id":34,"name":"First"}]' );
		self::assertSame( 'First', $this->provider->printers()[0]['name'] );
		$GLOBALS['wcip_test_options']['wcip_settings']['printnode_api_key'] = 'another-account-secret';
		$GLOBALS['wcip_http_response']['body'] = '[{"id":74,"name":"Second"}]';
		self::assertSame( 'Second', $this->provider->printers()[0]['name'] );
		self::assertCount( 2, $GLOBALS['wcip_test_transients'] );
		self::assertStringNotContainsString( 'secret', implode( ' ', array_keys( $GLOBALS['wcip_test_transients'] ) ) );
	}

	public function test_cached_printers_are_unavailable_when_credentials_are_removed(): void {
		$GLOBALS['wcip_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => '[{"id":34}]' );
		$this->provider->printers();
		$GLOBALS['wcip_test_options']['wcip_settings']['printnode_api_key'] = '';
		$error = $this->failure( fn() => $this->provider->printers() );
		self::assertSame( 'missing_credentials', $error->error_code() );
	}

	public function test_printers_refresh_and_paginate_without_truncation(): void {
		$requests = array();
		$GLOBALS['wcip_http_callback'] = static function ( string $url ) use ( &$requests ): array {
			$requests[] = $url;
			$ids = false !== strpos( $url, 'after=100' ) ? array( 101 ) : range( 1, 100 );
			return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array_map( static fn( $id ) => array( 'id' => $id, 'name' => 'Printer ' . $id ), $ids ) ) );
		};
		self::assertCount( 101, $this->provider->printers() );
		self::assertCount( 2, $requests );
		self::assertStringContainsString( 'after=100', $requests[1] );
		self::assertCount( 101, $this->provider->printers() );
		self::assertCount( 2, $requests );
		self::assertCount( 101, $this->provider->printers( true ) );
		self::assertCount( 4, $requests );
	}

	public function test_empty_printer_list_is_valid_and_cached(): void {
		$GLOBALS['wcip_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => '[]' );
		self::assertSame( array(), $this->provider->printers() );
		self::assertCount( 1, $GLOBALS['wcip_test_transients'] );
		$GLOBALS['wcip_http_response'] = new \WP_Error( 'timeout', 'Cache should avoid this request' );
		self::assertSame( array(), $this->provider->printers() );
	}

	/**
	 * @dataProvider malformed_printers
	 */
	public function test_malformed_printer_data_is_not_cached( string $body ): void {
		$GLOBALS['wcip_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => $body );
		$error = $this->failure( fn() => $this->provider->printers() );
		self::assertSame( 'malformed_response', $error->error_code() );
		self::assertSame( array(), $GLOBALS['wcip_test_transients'] );
	}

	public static function malformed_printers(): array {
		return array( array( '{}' ), array( 'null' ), array( '[true]' ), array( '[{"id":-34}]' ), array( '[{"id":34,"name":[]}]' ), array( '[{"id":34},{"id":34}]' ), array( '[{"id":34},{"id":33}]' ) );
	}

	private function failure( callable $operation ): ProviderException {
		try { $operation(); } catch ( ProviderException $error ) { return $error; }
		self::fail( 'Expected a provider exception.' );
	}
}
