<?php

namespace WCInvoicePrinter\Printing\PrintNode;

use WCInvoicePrinter\Printing\PrintProviderInterface;
use WCInvoicePrinter\Printing\ProviderException;
use WCInvoicePrinter\Printing\SubmissionResult;
use WCInvoicePrinter\Settings\SettingsRepository;

final class PrintNodeProvider implements PrintProviderInterface {
	private const BASE_URL = 'https://api.printnode.com';

	public function __construct( private readonly SettingsRepository $settings ) {}

	public function id(): string {
		return 'printnode';
	}

	public function test_connection(): array {
		$data = $this->request( 'GET', '/whoami' );
		return array(
			'connected' => true,
			'account'   => isset( $data['firstname'] ) ? sanitize_text_field( (string) $data['firstname'] ) : __( 'PrintNode account', 'wc-invoice-printer' ),
		);
	}

	public function printers( bool $force_refresh = false ): array {
		$cache_key = 'wcip_printnode_printers';
		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$data     = $this->request( 'GET', '/printers' );
		$printers = array();
		foreach ( $data as $printer ) {
			if ( ! is_array( $printer ) || empty( $printer['id'] ) ) {
				continue;
			}
			$printers[] = array(
				'id'          => (string) absint( $printer['id'] ),
				'name'        => sanitize_text_field( (string) ( $printer['name'] ?? __( 'Unnamed printer', 'wc-invoice-printer' ) ) ),
				'description' => sanitize_text_field( (string) ( $printer['description'] ?? '' ) ),
				'state'       => sanitize_key( (string) ( $printer['state'] ?? 'unknown' ) ),
			);
		}
		set_transient( $cache_key, $printers, 10 * MINUTE_IN_SECONDS );
		return $printers;
	}

	public function submit( string $pdf_bytes, string $printer_id, int $copies, string $title ): SubmissionResult {
		if ( ! ctype_digit( $printer_id ) || (int) $printer_id < 1 ) {
			throw new ProviderException( __( 'The selected printer is invalid.', 'wc-invoice-printer' ), 'invalid_printer', false, false );
		}
		$payload = array(
			'printerId'  => (int) $printer_id,
			'title'      => sanitize_text_field( $title ),
			'contentType'=> 'pdf_base64',
			'content'    => base64_encode( $pdf_bytes ),
			'source'     => 'WooCommerce Invoice Printer',
			'qty'        => max( 1, min( 20, $copies ) ),
		);
		$result = $this->request( 'POST', '/printjobs', $payload );
		$job_id = is_scalar( $result ) ? (string) $result : '';
		if ( '' === $job_id || ! ctype_digit( $job_id ) ) {
			throw new ProviderException( __( 'PrintNode returned an invalid job identifier.', 'wc-invoice-printer' ), 'malformed_response', false, false );
		}
		return new SubmissionResult( $job_id );
	}

	private function request( string $method, string $path, ?array $payload = null ): mixed {
		$api_key = $this->settings->api_key();
		if ( '' === $api_key ) {
			throw new ProviderException( __( 'PrintNode is not connected.', 'wc-invoice-printer' ), 'missing_credentials', false, false );
		}
		$args = array(
			'method'      => $method,
			'timeout'     => 25,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Basic ' . base64_encode( $api_key . ':' ),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $payload ) {
			$args['body'] = wp_json_encode( $payload );
		}
		$response = wp_safe_remote_request( self::BASE_URL . $path, $args );
		if ( is_wp_error( $response ) ) {
			throw new ProviderException( __( 'The PrintNode request may have been interrupted. Check the job before reprinting.', 'wc-invoice-printer' ), 'transport_unknown', false, true );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 ) {
			$retryable = 429 === $status || $status >= 500;
			$message   = 401 === $status
				? __( 'PrintNode rejected the API key.', 'wc-invoice-printer' )
				: sprintf( __( 'PrintNode rejected the request (HTTP %d).', 'wc-invoice-printer' ), $status );
			throw new ProviderException( $message, 'http_' . $status, $retryable, false );
		}
		$decoded = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new ProviderException( __( 'PrintNode returned an unreadable response.', 'wc-invoice-printer' ), 'malformed_response', false, false );
		}
		return $decoded;
	}
}
