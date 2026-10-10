<?php

namespace WCInvoicePrinter\Integration;

use WCInvoicePrinter\Printing\ProviderException;
use WCInvoicePrinter\Settings\SettingsRepository;

final class GenericWebhookAdapter implements ExportAdapterInterface {
	private SettingsRepository $settings;
	public function __construct( SettingsRepository $settings ) { $this->settings = $settings; }
	public function id(): string { return 'generic_webhook'; }
	public function capabilities(): array { return array( 'idempotency' => true, 'signed_requests' => true, 'delivery_lookup' => false ); }
	public function secret(): string {
		return defined( 'WCIP_EXPORT_SIGNING_SECRET' ) && is_string( WCIP_EXPORT_SIGNING_SECRET ) && '' !== WCIP_EXPORT_SIGNING_SECRET ? WCIP_EXPORT_SIGNING_SECRET : (string) $this->settings->get( 'export_signing_secret', '' );
	}
	public function validate_configuration(): void {
		$url = (string) $this->settings->get( 'export_endpoint', '' );
		$parts = parse_url( $url );
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ! $this->settings->get( 'export_enabled' ) || ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) || '' === $host || strtolower( (string) $this->settings->get( 'export_allowed_host' ) ) !== $host || ! wp_http_validate_url( $url ) || strlen( $this->secret() ) < 32 ) {
			throw new \InvalidArgumentException( 'Enable export and configure an allowed HTTPS host and a signing secret of at least 32 characters.' );
		}
		if ( 'localhost' === $host || false === strpos( $host, '.' ) || ( filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) ) { throw new \InvalidArgumentException( 'Private and local export destinations are not allowed.' ); }
	}
	public function test_connection(): array {
		$this->validate_configuration();
		// Explicit diagnostic call contains demo data only, never an order payload.
		$receipt = $this->submit( wp_json_encode( array( 'schema_version' => '1.0', 'event' => 'connection_test', 'items' => array() ) ), hash( 'sha256', wp_generate_uuid4() ) );
		return array( 'accepted' => true, 'receipt' => $receipt );
	}
	public function submit( string $payload, string $idempotency_key ): string {
		$this->validate_configuration();
		if ( strlen( $payload ) > 262144 || ! preg_match( '/^[a-f0-9]{64}$/D', $idempotency_key ) ) { throw new \InvalidArgumentException( 'Invalid export payload.' ); }
		$timestamp = (string) time();
		$signature = hash_hmac( 'sha256', $timestamp . "\n" . $idempotency_key . "\n" . $payload, $this->secret() );
		$response = wp_safe_remote_request( (string) $this->settings->get( 'export_endpoint' ), array( 'method' => 'POST', 'timeout' => 15, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 65536,
			'headers' => array( 'Content-Type' => 'application/json', 'Idempotency-Key' => $idempotency_key, 'X-WCIP-Timestamp' => $timestamp, 'X-WCIP-Signature' => 'sha256=' . $signature ), 'body' => $payload,
		) );
		if ( is_wp_error( $response ) ) { throw new ProviderException( 'Export outcome is unknown. Check the receiver before another export.', 'transport_unknown', false, true ); }
		$status = wp_remote_retrieve_response_code( $response );
		if ( 429 === $status ) { throw new ProviderException( 'The receiver rejected this attempt due to rate limiting.', 'http_429', true, false ); }
		if ( 408 === $status || $status >= 500 ) { throw new ProviderException( 'Receiver outcome is ambiguous. Check its idempotency receipt.', 'http_unknown', false, true ); }
		if ( $status < 200 || $status >= 300 ) { throw new ProviderException( 'The receiver rejected this export.', 'http_rejected', false, false ); }
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || true !== ( $data['accepted'] ?? false ) || ! is_string( $data['receipt'] ?? null ) || ! preg_match( '/^[A-Za-z0-9._:-]{1,191}$/D', $data['receipt'] ) ) { throw new ProviderException( 'The receiver did not return a valid acceptance receipt. Investigate before exporting again.', 'receipt_unknown', false, true ); }
		return $data['receipt'];
	}
}
