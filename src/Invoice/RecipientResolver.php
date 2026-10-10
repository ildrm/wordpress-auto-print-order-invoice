<?php

namespace WCInvoicePrinter\Invoice;

use WCInvoicePrinter\Settings\SettingsRepository;

/** Explicit shipping values, trusted mappings, then optional billing fallback. */
final class RecipientResolver {
	private SettingsRepository $settings;
	public function __construct( SettingsRepository $settings ) { $this->settings = $settings; }

	public function resolve( \WC_Order $order ): array {
		$address = method_exists( $order, 'get_address' ) ? $order->get_address( 'shipping' ) : array();
		$billing = method_exists( $order, 'get_address' ) ? $order->get_address( 'billing' ) : array();
		$inherited = array();
		$phone = method_exists( $order, 'get_shipping_phone' ) ? (string) $order->get_shipping_phone() : '';
		$map = json_decode( (string) $this->settings->get( 'shipping_field_mapping', '{}' ), true );
		$extra = array();
		foreach ( is_array( $map ) ? $map : array() as $field => $key ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[A-Za-z0-9_-]{1,100}$/D', $key ) ) { continue; }
			$value = $order->get_meta( $key, true );
			if ( ! is_scalar( $value ) ) { continue; }
			$value = sanitize_text_field( (string) $value );
			if ( 'phone' === $field && '' === $phone ) { $phone = $value; }
			elseif ( in_array( $field, array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ), true ) ) {
				if ( empty( $address[ $field ] ) ) { $address[ $field ] = $value; }
			} elseif ( preg_match( '/^[a-z][a-z0-9_]{0,39}$/D', (string) $field ) && '' !== $value ) { $extra[ $field ] = $value; }
		}
		if ( $this->settings->get( 'shipping_billing_fallback', true ) ) {
			foreach ( $billing as $field => $value ) {
				if ( empty( $address[ $field ] ) && ! in_array( $field, array( 'email', 'phone' ), true ) ) { $address[ $field ] = $value; $inherited[] = $field; }
			}
			if ( '' === $phone ) { $phone = $order->get_billing_phone(); if ( '' !== $phone ) { $inherited[] = 'phone'; } }
		}
		$address = array_map( static function ( $value ): string { return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : ''; }, $address );
		$formatted = '';
		if ( function_exists( 'WC' ) && WC()->countries ) { $formatted = WC()->countries->get_formatted_address( $address, "\n" ); }
		if ( '' === $formatted ) { $formatted = $order->get_formatted_shipping_address(); }
		if ( '' === $formatted && $this->settings->get( 'shipping_billing_fallback', true ) ) { $formatted = $order->get_formatted_billing_address(); }
		$formatted = trim( html_entity_decode( wp_strip_all_tags( preg_replace( '/<br\s*\/?\s*>/i', "\n", $formatted ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$name = trim( ( $address['first_name'] ?? '' ) . ' ' . ( $address['last_name'] ?? '' ) );
		$data = array( 'name' => $name, 'address' => $address, 'formatted_address' => $formatted, 'phone' => sanitize_text_field( $phone ), 'extra' => $extra, 'inherited' => $inherited,
			'requires_shipping' => method_exists( $order, 'needs_shipping_address' ) ? $order->needs_shipping_address() : '' !== $order->get_formatted_shipping_address(),
		);
		return apply_filters( 'wcip_recipient_data', $data, $order );
	}
}
