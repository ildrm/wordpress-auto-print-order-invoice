<?php

namespace WCInvoicePrinter\Settings;

final class SettingsRepository {
	private const OPTION = 'wcip_settings';

	public function all(): array {
		$defaults = array(
			'business_name'          => get_bloginfo( 'name' ),
			'business_details'       => '',
			'business_phone'         => '',
			'business_email'         => get_option( 'admin_email', '' ),
			'logo_url'               => '',
			'default_template'       => 'classic',
			'show_customer_note'     => false,
			'automatic_enabled'      => false,
			'automatic_template'     => 'classic',
			'automatic_copies'       => 1,
			'printnode_api_key'      => '',
			'printnode_printer_id'   => '',
			'printnode_printer_name' => '',
		);
		$value = get_option( self::OPTION, array() );
		return array_merge( $defaults, $this->sanitize( is_array( $value ) ? $value : array() ) );
	}

	/**
	 * @param mixed $default
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$settings = $this->all();
		return $settings[ $key ] ?? $default;
	}

	public function api_key(): string {
		if ( $this->api_key_is_external() ) {
			return trim( WCIP_PRINTNODE_API_KEY );
		}
		return (string) $this->get( 'printnode_api_key', '' );
	}

	public function api_key_is_external(): bool {
		return defined( 'WCIP_PRINTNODE_API_KEY' ) && is_string( WCIP_PRINTNODE_API_KEY ) && '' !== trim( WCIP_PRINTNODE_API_KEY );
	}

	public function update( array $changes ): void {
		$old_key = $this->api_key();
		$changes = $this->sanitize( $changes );
		if ( $this->api_key_is_external() ) {
			unset( $changes['printnode_api_key'] );
		}
		update_option( self::OPTION, array_merge( $this->all(), $changes ), false );
		if ( $old_key !== $this->api_key() ) {
			delete_transient( 'wcip_printnode_printers' );
			delete_transient( 'wcip_printnode_printers_' . hash( 'sha256', $old_key ) );
			delete_transient( 'wcip_printnode_printers_' . hash( 'sha256', $this->api_key() ) );
		}
	}

	/** Normalize persisted settings and reject unknown keys and non-scalar input. */
	private function sanitize( array $settings ): array {
		$clean = array();
		foreach ( $settings as $key => $value ) {
			if ( ! is_scalar( $value ) && null !== $value ) {
				continue;
			}
			switch ( $key ) {
				case 'business_name':
				case 'business_phone':
				case 'printnode_printer_name':
				case 'printnode_api_key':
					$clean[ $key ] = sanitize_text_field( (string) $value );
					break;
				case 'business_details':
					$clean[ $key ] = sanitize_textarea_field( (string) $value );
					break;
				case 'business_email':
					$clean[ $key ] = sanitize_email( (string) $value );
					break;
				case 'logo_url':
					$clean[ $key ] = esc_url_raw( (string) $value, array( 'http', 'https' ) );
					break;
				case 'default_template':
				case 'automatic_template':
					$clean[ $key ] = sanitize_key( (string) $value ) ?: 'classic';
					break;
				case 'show_customer_note':
				case 'automatic_enabled':
					$clean[ $key ] = in_array( $value, array( true, 1, '1' ), true );
					break;
				case 'automatic_copies':
					$clean[ $key ] = max( 1, min( 20, (int) $value ) );
					break;
				case 'printnode_printer_id':
					$clean[ $key ] = preg_match( '/^[1-9][0-9]*$/D', (string) $value ) ? (string) $value : '';
					break;
			}
		}
		return $clean;
	}
}
