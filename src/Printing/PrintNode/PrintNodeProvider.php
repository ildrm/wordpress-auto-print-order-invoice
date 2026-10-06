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
		if ( ! is_array( $data ) || '' === $this->positive_id( $data['id'] ?? null ) || ( isset( $data['firstname'] ) && ! is_string( $data['firstname'] ) ) ) {
			throw $this->malformed_response();
		}
		return array(
			'connected' => true,
			'account'   => isset( $data['firstname'] ) ? sanitize_text_field( (string) $data['firstname'] ) : __( 'PrintNode account', 'wc-invoice-printer' ),
		);
	}

	public function printers( bool $force_refresh = false ): array {
		$api_key = $this->settings->api_key();
		if ( '' === $api_key ) {
			throw new ProviderException( __( 'PrintNode is not connected.', 'wc-invoice-printer' ), 'missing_credentials', false, false );
		}
		// Printer identifiers belong to an account, so never reuse another API key's cache.
		$cache_key = 'wcip_printnode_printers_' . hash( 'sha256', $api_key );
		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$printers = array();
		$after = 0;
		// PrintNode returns at most 100 records by default. Fetch every page before caching.
		for ( $page = 0; $page < 100; ++$page ) {
			$path = '/printers?limit=100&dir=asc' . ( $after ? '&after=' . $after : '' );
			$data = $this->request( 'GET', $path );
			if ( ! is_array( $data ) || ! array_is_list( $data ) ) {
				throw $this->malformed_response();
			}
			foreach ( $data as $printer ) {
				$id = is_array( $printer ) ? $this->positive_id( $printer['id'] ?? null ) : '';
				if ( '' === $id || (int) $id <= $after ) {
					throw $this->malformed_response();
				}
				foreach ( array( 'name', 'description', 'state' ) as $field ) {
					if ( isset( $printer[ $field ] ) && ! is_string( $printer[ $field ] ) ) {
						throw $this->malformed_response();
					}
				}
				$printers[] = array(
					'id'          => $id,
					'name'        => sanitize_text_field( $printer['name'] ?? __( 'Unnamed printer', 'wc-invoice-printer' ) ),
					'description' => sanitize_text_field( $printer['description'] ?? '' ),
					'state'       => sanitize_key( $printer['state'] ?? 'unknown' ),
				);
				$after = (int) $id;
			}
			if ( count( $data ) < 100 ) {
				set_transient( $cache_key, $printers, 10 * MINUTE_IN_SECONDS );
				return $printers;
			}
		}
		throw $this->malformed_response();
	}

	public function submit( string $pdf_bytes, string $printer_id, int $copies, string $title ): SubmissionResult {
		if ( '' === $this->positive_id( $printer_id ) ) {
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
		$job_id = $this->positive_id( $result );
		if ( '' === $job_id ) {
			throw $this->malformed_response( true );
		}
		return new SubmissionResult( $job_id );
	}

	private function request( string $method, string $path, ?array $payload = null ): mixed {
		$is_submission = 'POST' === $method;
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
			if ( false === $args['body'] ) {
				throw new ProviderException( __( 'The print request could not be encoded.', 'wc-invoice-printer' ), 'invalid_payload', false, false );
			}
		}
		$response = wp_safe_remote_request( self::BASE_URL . $path, $args );
		if ( is_wp_error( $response ) ) {
			throw new ProviderException( __( 'The PrintNode request may have been interrupted. Check the job before reprinting.', 'wc-invoice-printer' ), 'transport_unknown', ! $is_submission, $is_submission );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 ) {
			// A server/proxy failure can arrive after a POST has created the job.
			// Without a remote idempotency key it is unsafe to submit it again.
			$ambiguous = $is_submission && ( $status < 200 || ( $status >= 300 && $status < 400 ) || 408 === $status || $status >= 500 );
			$retryable = ! $ambiguous && ( 429 === $status || $status >= 500 );
			$message   = 401 === $status
				? __( 'PrintNode rejected the API key.', 'wc-invoice-printer' )
				: sprintf( __( 'PrintNode rejected the request (HTTP %d).', 'wc-invoice-printer' ), $status );
			if ( $ambiguous ) {
				$message = __( 'PrintNode may have accepted the print request. Check the job before reprinting.', 'wc-invoice-printer' );
			}
			throw new ProviderException( $message, 'http_' . $status, $retryable, $ambiguous );
		}
		$decoded = json_decode( $body, true );
		$first_character = substr( ltrim( $body ), 0, 1 );
		if ( JSON_ERROR_NONE !== json_last_error() || ( str_starts_with( $path, '/printers' ) && '[' !== $first_character ) || ( '/whoami' === $path && '{' !== $first_character ) ) {
			throw $this->malformed_response( $is_submission );
		}
		return $decoded;
	}

	private function positive_id( mixed $value ): string {
		if ( ( ! is_int( $value ) && ! is_string( $value ) ) || ! preg_match( '/^[1-9][0-9]*$/', (string) $value ) ) {
			return '';
		}
		$id = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		return false === $id ? '' : (string) $id;
	}

	private function malformed_response( bool $ambiguous = false ): ProviderException {
		$message = $ambiguous
			? __( 'PrintNode returned an unreadable job response. Check the job before reprinting.', 'wc-invoice-printer' )
			: __( 'PrintNode returned an unreadable response.', 'wc-invoice-printer' );
		return new ProviderException( $message, 'malformed_response', false, $ambiguous );
	}
}
