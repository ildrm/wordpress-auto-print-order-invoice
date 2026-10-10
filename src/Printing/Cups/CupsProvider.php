<?php

namespace WCInvoicePrinter\Printing\Cups;

use WCInvoicePrinter\Printing\PrintProviderInterface;
use WCInvoicePrinter\Printing\ProviderException;
use WCInvoicePrinter\Printing\SubmissionResult;
use WCInvoicePrinter\Settings\SettingsRepository;

/** Direct HTTPS IPP to a self-hosted, open-source CUPS server. No cloud service. */
final class CupsProvider implements PrintProviderInterface {
	private SettingsRepository $settings;
	public function __construct( SettingsRepository $settings ) { $this->settings = $settings; }
	public function id(): string { return 'cups'; }
	public static function valid_printer( string $id ): bool { return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,126}$/D', $id ); }

	public function test_connection(): array {
		$this->printers( true );
		return array( 'connected' => true, 'account' => __( 'Self-hosted CUPS server', 'wc-invoice-printer' ) );
	}

	public function printers( bool $force_refresh = false ): array {
		$this->endpoint();
		$key = 'wcip_cups_printers_' . $this->settings->cups_fingerprint();
		if ( ! $force_refresh ) { $cached = get_transient( $key ); if ( is_array( $cached ) ) { return $cached; } }
		$uri = preg_replace( '#^https://#', 'ipps://', $this->endpoint() ) . '/';
		$response = $this->request( 0x4002, '/', IppCodec::attribute( 0x45, 'printer-uri', $uri ) . IppCodec::attribute( 0x44, 'requested-attributes', 'printer-name' ) . IppCodec::attribute( 0x44, '', 'printer-info' ) . IppCodec::attribute( 0x44, '', 'printer-state' ) );
		$printers = array(); $seen = array();
		foreach ( $response['groups'] as $group ) {
			if ( 4 !== $group['tag'] ) { continue; }
			$id = $this->value( $group, 'printer-name', 0x42 );
			if ( ! is_string( $id ) || ! self::valid_printer( $id ) || isset( $seen[ $id ] ) ) { throw $this->malformed( false ); }
			$seen[ $id ] = true;
			$description = $this->value( $group, 'printer-info', 0x41 );
			$state = $this->value( $group, 'printer-state', 0x23 );
			$printers[] = array( 'id' => $id, 'name' => sanitize_text_field( $id ), 'description' => is_string( $description ) ? sanitize_text_field( $description ) : '', 'state' => in_array( $state, array( 3, 4 ), true ) ? 'online' : ( 5 === $state ? 'offline' : 'unknown' ) );
		}
		set_transient( $key, $printers, 10 * MINUTE_IN_SECONDS ); return $printers;
	}

	public function submit( string $pdf_bytes, string $printer_id, int $copies, string $title ): SubmissionResult {
		if ( ! self::valid_printer( $printer_id ) ) { throw new ProviderException( __( 'The selected printer is invalid.', 'wc-invoice-printer' ), 'invalid_printer', false, false ); }
		if ( $copies < 1 || $copies > 20 || strlen( $pdf_bytes ) > 20 * 1024 * 1024 || 0 !== strpos( $pdf_bytes, '%PDF-' ) ) { throw new ProviderException( __( 'The print document or copy count is invalid.', 'wc-invoice-printer' ), 'invalid_document', false, false ); }
		$path = '/printers/' . $printer_id;
		$uri = preg_replace( '#^https://#', 'ipps://', $this->endpoint() ) . $path;
		$attributes = IppCodec::attribute( 0x45, 'printer-uri', $uri )
			. IppCodec::attribute( 0x42, 'requesting-user-name', (string) $this->settings->get( 'cups_username' ) ?: 'wc-invoice-printer' )
			. IppCodec::attribute( 0x42, 'job-name', mb_strcut( sanitize_text_field( $title ), 0, 255, 'UTF-8' ) )
			. IppCodec::attribute( 0x22, 'ipp-attribute-fidelity', "\x01" )
			. IppCodec::attribute( 0x49, 'document-format', 'application/pdf' );
		$response = $this->request( 2, $path, $attributes, IppCodec::attribute( 0x21, 'copies', pack( 'N', $copies ) ), $pdf_bytes );
		foreach ( $response['groups'] as $group ) {
			if ( 2 !== $group['tag'] ) { continue; }
			$id = $this->value( $group, 'job-id', 0x21 );
			if ( is_int( $id ) && $id > 0 && $id <= 2147483647 ) { return new SubmissionResult( (string) $id ); }
		}
		throw $this->malformed( true );
	}

	private function endpoint(): string {
		$url = $this->settings->cups_endpoint(); $parts = parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || isset( $parts['user'], $parts['pass'] ) || isset( $parts['user'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || ! in_array( $parts['path'] ?? '', array( '', '/' ), true ) || ! in_array( $parts['port'] ?? 443, array( 443, 631 ), true ) ) {
			throw new ProviderException( __( 'Configure a valid HTTPS CUPS server address.', 'wc-invoice-printer' ), 'invalid_endpoint', false, false );
		}
		if ( '' !== $this->settings->get( 'cups_username' ) && '' === $this->settings->cups_password() ) { throw new ProviderException( __( 'Configure the CUPS password before printing.', 'wc-invoice-printer' ), 'missing_credentials', false, false ); }
		return rtrim( $url, '/' );
	}

	private function request( int $operation, string $path, string $attributes, string $job_attributes = '', string $pdf = '' ): array {
		$endpoint = $this->endpoint(); $submission = 2 === $operation; $id = random_int( 1, 2147483647 );
		$args = array( 'method' => 'POST', 'timeout' => 30, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 2 * 1024 * 1024 + 1, 'headers' => array( 'Content-Type' => 'application/ipp', 'Accept' => 'application/ipp' ), 'body' => IppCodec::request( $operation, $id, $attributes, $job_attributes, $pdf ), 'data_format' => 'body' );
		if ( '' !== $this->settings->get( 'cups_username' ) ) { $args['headers']['Authorization'] = 'Basic ' . base64_encode( $this->settings->get( 'cups_username' ) . ':' . $this->settings->cups_password() ); }
		if ( defined( 'WCIP_CUPS_CA_BUNDLE' ) && is_string( WCIP_CUPS_CA_BUNDLE ) ) {
			if ( ! is_readable( WCIP_CUPS_CA_BUNDLE ) || ! is_file( WCIP_CUPS_CA_BUNDLE ) ) { throw new ProviderException( __( 'The CUPS certificate bundle is unavailable.', 'wc-invoice-printer' ), 'invalid_ca_bundle', false, false ); }
			$args['sslcertificates'] = WCIP_CUPS_CA_BUNDLE;
		}
		$host = strtolower( (string) parse_url( $endpoint, PHP_URL_HOST ) );
		// A deployment-owned allowlist authorizes a specific private/VPN host and
		// port 631. Database settings cannot bypass WordPress SSRF protection.
		$trusted = defined( 'WCIP_CUPS_TRUSTED_HOSTS' ) && is_array( WCIP_CUPS_TRUSTED_HOSTS ) && in_array( $host, WCIP_CUPS_TRUSTED_HOSTS, true );
		$response = $trusted ? wp_remote_request( $endpoint . $path, $args ) : wp_safe_remote_request( $endpoint . $path, $args );
		if ( is_wp_error( $response ) ) { throw new ProviderException( __( 'The CUPS request could not be completed. Check the server and paper output before reprinting.', 'wc-invoice-printer' ), 'transport_error', ! $submission, $submission ); }
		$http = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $http ) {
			$ambiguous = $submission && ( $http < 400 || 408 === $http || $http >= 500 );
			throw new ProviderException( __( 'The CUPS server rejected the request or returned an uncertain result.', 'wc-invoice-printer' ), 'http_' . $http, 429 === $http && ! $ambiguous, $ambiguous );
		}
		try {
			if ( 'application/ipp' !== strtolower( trim( explode( ';', (string) wp_remote_retrieve_header( $response, 'content-type' ) )[0] ) ) ) { throw new \UnexpectedValueException( 'Invalid content type.' ); }
			$decoded = IppCodec::response( wp_remote_retrieve_body( $response ), $id );
		} catch ( \Throwable $error ) { throw $this->malformed( $submission ); }
		if ( 0 !== $decoded['status'] ) {
			// Error-class responses explicitly reject the operation. A success with
			// substitutions may have printed different options and is uncertain.
			$ambiguous = $submission && ( $decoded['status'] < 0x0400 || $decoded['status'] >= 0x0500 );
			throw new ProviderException( __( 'CUPS did not accept the requested print options. Check its queue before reprinting.', 'wc-invoice-printer' ), 'ipp_' . dechex( $decoded['status'] ), false, $ambiguous );
		}
		return $decoded;
	}

	private function value( array $group, string $name, int $tag ) {
		$values = $group['attributes'][ $name ] ?? array();
		return 1 === count( $values ) && $tag === $values[0]['tag'] ? $values[0]['value'] : null;
	}
	private function malformed( bool $submission ): ProviderException {
		return new ProviderException( __( 'CUPS returned an invalid response. Check its queue before reprinting.', 'wc-invoice-printer' ), 'malformed_response', false, $submission );
	}
}
