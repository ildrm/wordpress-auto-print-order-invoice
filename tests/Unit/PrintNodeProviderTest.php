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
		$this->provider = new PrintNodeProvider( new SettingsRepository() );
	}

	public function test_submission_normalizes_external_job_id_and_uses_base64(): void {
		$GLOBALS['wcip_http_response'] = array( 'response' => array( 'code' => 201 ), 'body' => '623' );
		$result = $this->provider->submit( '%PDF-test', '34', 2, 'Invoice 42' );
		self::assertSame( '623', $result->external_job_id );
		$payload = json_decode( $GLOBALS['wcip_last_http'][1]['body'], true );
		self::assertSame( 'pdf_base64', $payload['contentType'] );
		self::assertSame( 2, $payload['qty'] );
		self::assertSame( '%PDF-test', base64_decode( $payload['content'], true ) );
	}

	public function test_transport_error_is_ambiguous_and_not_retryable(): void {
		$GLOBALS['wcip_http_response'] = new \WP_Error( 'timeout', 'secret-bearing raw transport message' );
		try { $this->provider->submit( 'pdf', '34', 1, 'Invoice' ); self::fail( 'Expected exception' ); } catch ( ProviderException $error ) { self::assertTrue( $error->ambiguous() ); self::assertFalse( $error->retryable() ); self::assertStringNotContainsString( 'secret-bearing', $error->getMessage() ); }
	}

	public function test_server_rejection_is_definite_and_retryable(): void {
		$GLOBALS['wcip_http_response'] = array( 'response' => array( 'code' => 503 ), 'body' => '{"secret":"not surfaced"}' );
		try { $this->provider->submit( 'pdf', '34', 1, 'Invoice' ); self::fail( 'Expected exception' ); } catch ( ProviderException $error ) { self::assertFalse( $error->ambiguous() ); self::assertTrue( $error->retryable() ); self::assertSame( 'http_503', $error->error_code() ); }
	}

	public function test_invalid_printer_never_reaches_network(): void {
		$this->expectException( ProviderException::class );
		$this->provider->submit( 'pdf', '../34', 1, 'Invoice' );
	}
}
