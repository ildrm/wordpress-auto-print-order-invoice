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
		return wp_parse_args( is_array( $value ) ? $value : array(), $defaults );
	}

	public function get( string $key, mixed $default = null ): mixed {
		$settings = $this->all();
		return $settings[ $key ] ?? $default;
	}

	public function api_key(): string {
		if ( defined( 'WCIP_PRINTNODE_API_KEY' ) && is_string( WCIP_PRINTNODE_API_KEY ) ) {
			return trim( WCIP_PRINTNODE_API_KEY );
		}
		return (string) $this->get( 'printnode_api_key', '' );
	}

	public function api_key_is_external(): bool {
		return defined( 'WCIP_PRINTNODE_API_KEY' ) && '' !== $this->api_key();
	}

	public function update( array $changes ): void {
		update_option( self::OPTION, array_merge( $this->all(), $changes ), false );
	}
}
