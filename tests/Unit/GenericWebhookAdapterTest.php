<?php

namespace WCInvoicePrinter\Tests\Unit;

use WCInvoicePrinter\Integration\GenericWebhookAdapter;
use WCInvoicePrinter\Printing\ProviderException;
use WCInvoicePrinter\Invoice\DocumentCodeService;
use WCInvoicePrinter\Tests\Support\JobTestCase;

final class GenericWebhookAdapterTest extends JobTestCase {
	private function adapter(): GenericWebhookAdapter {
		$this->settings->update( array( 'export_enabled' => true, 'export_endpoint' => 'https://receiver.example.com/v1', 'export_allowed_host' => 'receiver.example.com', 'export_signing_secret' => str_repeat( 's', 32 ) ) );
		return new GenericWebhookAdapter( $this->settings );
	}
	public function test_signature_covers_exact_body_timestamp_and_idempotency_key(): void {
		$adapter = $this->adapter(); $body = '{"schema_version":"1.0"}'; $key = str_repeat( 'a', 64 );
		$GLOBALS['wcip_http_response'] = array( 'response' => array( 'code' => 202 ), 'body' => '{"accepted":true,"receipt":"receipt-42"}' );
		$this->assertSame( 'receipt-42', $adapter->submit( $body, $key ) );
		$args = $GLOBALS['wcip_last_http'][1];
		$this->assertSame( 'sha256=' . hash_hmac( 'sha256', $args['headers']['X-WCIP-Timestamp'] . "\n" . $key . "\n" . $body, str_repeat( 's', 32 ) ), $args['headers']['X-WCIP-Signature'] );
		$this->assertSame( 0, $args['redirection'] ); $this->assertTrue( $args['sslverify'] );
		$this->assertStringNotContainsString( str_repeat( 's', 32 ), json_encode( $args ) );
	}
	/** @dataProvider unsafe_destinations */
	public function test_private_plaintext_and_nonallowlisted_destinations_are_rejected( string $url, string $host ): void {
		$adapter = $this->adapter(); $this->settings->update( array( 'export_endpoint' => $url, 'export_allowed_host' => $host ) );
		$this->expectException( \InvalidArgumentException::class ); $adapter->validate_configuration();
	}
	public static function unsafe_destinations(): array { return array( array( 'http://receiver.example.com', 'receiver.example.com' ), array( 'https://127.0.0.1/path', '127.0.0.1' ), array( 'https://10.0.0.1', '10.0.0.1' ), array( 'https://localhost', 'localhost' ), array( 'https://wrong.example.com', 'receiver.example.com' ), array( 'https://user:pass@receiver.example.com', 'receiver.example.com' ), array( 'https://receiver.example.com:444', 'receiver.example.com' ) ); }
	/** @dataProvider ambiguous_results */
	public function test_ambiguous_responses_forbid_retry( $response ): void {
		$adapter = $this->adapter(); $GLOBALS['wcip_http_response'] = $response;
		try { $adapter->submit( '{}', str_repeat( 'a', 64 ) ); $this->fail( 'Expected uncertainty.' ); }
		catch ( ProviderException $error ) { $this->assertTrue( $error->ambiguous() ); $this->assertFalse( $error->retryable() ); }
	}
	public static function ambiguous_results(): array { return array( array( new \WP_Error( 'timeout', 'secret-not-for-logs' ) ), array( array( 'response' => array( 'code' => 408 ), 'body' => '' ) ), array( array( 'response' => array( 'code' => 500 ), 'body' => '' ) ), array( array( 'response' => array( 'code' => 200 ), 'body' => '{}' ) ) ); }
	public function test_rate_limit_is_definite_rejection(): void {
		$adapter = $this->adapter(); $GLOBALS['wcip_http_response'] = array( 'response' => array( 'code' => 429 ), 'body' => '' );
		try { $adapter->submit( '{}', str_repeat( 'b', 64 ) ); $this->fail( 'Expected rejection.' ); }
		catch ( ProviderException $error ) { $this->assertFalse( $error->ambiguous() ); $this->assertTrue( $error->retryable() ); }
	}
	public function test_codes_are_local_images_and_only_accept_opaque_identifiers(): void {
		$service = new DocumentCodeService(); $reference = 'W1-' . str_repeat( 'a', 16 );
		foreach ( array( 'Code128', 'QR' ) as $type ) {
			$image = $service->image( $reference, $type ); $this->assertStringStartsWith( 'data:image/svg+xml;base64,', $image );
			$svg = base64_decode( substr( $image, strpos( $image, ',' ) + 1 ) );
			$this->assertStringContainsString( '<svg', $svg ); $this->assertStringNotContainsString( 'example.com', $svg );
		}
		$this->expectException( \InvalidArgumentException::class ); $service->image( 'Customer +98 phone', 'QR' );
	}
}
