<?php

namespace WCInvoicePrinter\Settings;

final class SettingsRepository {
	private const OPTION = 'wcip_settings';

	public function all(): array {
		$defaults = array(
			'document_timezone' => 'site',
			'shipping_billing_fallback' => true,
			'shipping_field_mapping' => '{}',
			'show_paid_date' => true,
			'show_shipping_phone' => true,
			'label_show_sender' => true,
			'automatic_eligibility' => 'confirmed_payment',
			'reconciliation_interval' => 600,
			'fulfillment_on_confirmation' => false,
			'fulfillment_confirmation_stage' => 'preparing',
			'barcode_enabled' => false,
			'barcode_type' => 'Code128',
			'export_enabled' => false,
			'export_on_scan' => false,
			'export_on_packed' => false,
			'export_include_recipient' => false,
			'export_endpoint' => '',
			'export_allowed_host' => '',
			'export_signing_secret' => '',
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
			'automatic_provider'     => 'cups',
			'agent_queue'            => 'Office',
			'agent_user_id'          => 0,
			'cups_endpoint'      => '',
			'cups_username'      => '',
			'cups_password'      => '',
			'cups_printer_id'   => '',
			'cups_printer_name' => '',
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

	public function cups_endpoint(): string {
		return (string) $this->get( 'cups_endpoint', '' );
	}

	public function agent_ready(): bool {
		$id = (int) $this->get( 'agent_user_id' );
		if ( $id < 1 || ! \WCInvoicePrinter\Printing\Cups\CupsProvider::valid_printer( (string) $this->get( 'agent_queue' ) ) ) { return false; }
		if ( function_exists( 'get_userdata' ) ) { $user = get_userdata( $id ); return $user && $user->has_cap( 'wcip_run_print_agent' ); }
		return true;
	}
	public function automatic_printer(): string { return (string) $this->get( 'agent' === $this->get( 'automatic_provider' ) ? 'agent_queue' : 'cups_printer_id', '' ); }
	public function automatic_ready(): bool { return 'agent' === $this->get( 'automatic_provider' ) ? $this->agent_ready() : ( '' !== $this->cups_endpoint() && \WCInvoicePrinter\Printing\Cups\CupsProvider::valid_printer( $this->automatic_printer() ) ); }

	public function cups_password(): string {
		if ( $this->cups_password_is_external() ) {
			return WCIP_CUPS_PASSWORD;
		}
		return (string) $this->get( 'cups_password', '' );
	}

	public function cups_fingerprint(): string {
		return hash( 'sha256', wp_json_encode( array( $this->cups_endpoint(), $this->get( 'cups_username' ), $this->cups_password() ) ) );
	}

	public function cups_password_is_external(): bool {
		return defined( 'WCIP_CUPS_PASSWORD' ) && is_string( WCIP_CUPS_PASSWORD ) && '' !== trim( WCIP_CUPS_PASSWORD );
	}

	public function export_secret_is_external(): bool {
		return defined( 'WCIP_EXPORT_SIGNING_SECRET' ) && is_string( WCIP_EXPORT_SIGNING_SECRET ) && '' !== trim( WCIP_EXPORT_SIGNING_SECRET );
	}

	public function update( array $changes ): void {
		$old_key = $this->cups_fingerprint();
		$was_automatic = (bool) $this->get( 'automatic_enabled' );
		$changes = $this->sanitize( $changes );
		if ( $this->cups_password_is_external() ) {
			unset( $changes['cups_password'] );
		}
		if ( $this->export_secret_is_external() ) { unset( $changes['export_signing_secret'] ); }
		update_option( self::OPTION, array_merge( $this->all(), $changes ), false );
		if ( ! $was_automatic && $this->get( 'automatic_enabled' ) ) { update_option( 'wcip_discovery_since', time(), false ); delete_option( 'wcip_reconciliation_state' ); }
		if ( $old_key !== $this->cups_fingerprint() ) {
			delete_transient( 'wcip_cups_printers_' . $old_key );
			delete_transient( 'wcip_cups_printers_' . $this->cups_fingerprint() );
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
				case 'automatic_provider':
					$clean[ $key ] = 'agent' === $value ? 'agent' : 'cups'; break;
				case 'agent_user_id':
					$clean[ $key ] = absint( $value ); break;
				case 'agent_queue':
					$clean[ $key ] = \WCInvoicePrinter\Printing\Cups\CupsProvider::valid_printer( (string) $value ) ? (string) $value : ''; break;
				case 'fulfillment_confirmation_stage':
					$clean[ $key ] = in_array( $value, array( 'preparing', 'packed', 'ready_to_ship' ), true ) ? $value : 'preparing'; break;
				case 'business_name':
				case 'business_phone':
				case 'cups_printer_name':
					$clean[ $key ] = sanitize_text_field( (string) $value );
					break;
				case 'cups_endpoint':
					$clean[ $key ] = esc_url_raw( trim( (string) $value ), array( 'https' ) ); break;
				case 'cups_username':
					$clean[ $key ] = preg_match( '/^[^:\r\n\x00]{0,127}$/D', (string) $value ) ? trim( (string) $value ) : ''; break;
				case 'cups_password':
					$clean[ $key ] = strlen( (string) $value ) <= 512 && false === strpos( (string) $value, "\0" ) ? (string) $value : ''; break;
				case 'document_timezone':
					try { if ( 'site' !== $value ) { new \DateTimeZone( (string) $value ); } $clean[ $key ] = (string) $value; } catch ( \Exception $error ) { $clean[ $key ] = 'site'; } break;
				case 'export_allowed_host':
				case 'export_signing_secret':
					$clean[ $key ] = sanitize_text_field( (string) $value ); break;
				case 'export_endpoint':
					$clean[ $key ] = esc_url_raw( (string) $value, array( 'https' ) ); break;
				case 'automatic_eligibility':
					$clean[ $key ] = 'fulfillment' === $value ? 'fulfillment' : 'confirmed_payment'; break;
				case 'barcode_type':
					$clean[ $key ] = 'QR' === $value ? 'QR' : 'Code128'; break;
				case 'reconciliation_interval':
					$clean[ $key ] = max( 300, min( 3600, (int) $value ) ); break;
				case 'shipping_field_mapping':
					$map = json_decode( (string) $value, true );
					$clean[ $key ] = is_array( $map ) && count( $map ) <= 20 ? wp_json_encode( $map ) : '{}'; break;
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
				case 'shipping_billing_fallback':
				case 'show_paid_date':
				case 'show_shipping_phone':
				case 'label_show_sender':
				case 'fulfillment_on_confirmation':
				case 'barcode_enabled':
				case 'export_enabled':
				case 'export_on_scan':
				case 'export_on_packed':
				case 'export_include_recipient':
				case 'show_customer_note':
				case 'automatic_enabled':
					$clean[ $key ] = in_array( $value, array( true, 1, '1' ), true );
					break;
				case 'automatic_copies':
					$clean[ $key ] = max( 1, min( 20, (int) $value ) );
					break;
				case 'cups_printer_id':
					$clean[ $key ] = \WCInvoicePrinter\Printing\Cups\CupsProvider::valid_printer( (string) $value ) ? (string) $value : '';
					break;
			}
		}
		return $clean;
	}
}
