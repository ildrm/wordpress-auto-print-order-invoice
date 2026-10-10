<?php

namespace WCInvoicePrinter\Automation;

use WCInvoicePrinter\Settings\SettingsRepository;

/** WooCommerce payment evidence is not a guarantee of banking settlement. */
final class PaymentEligibilityPolicy {
	private SettingsRepository $settings;
	public function __construct( SettingsRepository $settings ) { $this->settings = $settings; }

	public function confirmed( \WC_Order $order ): bool {
		if ( in_array( $order->get_status(), array( 'cancelled', 'failed', 'refunded', 'trash', 'checkout-draft' ), true ) ) { return false; }
		$gateway = null;
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			$gateway = $gateways[ $order->get_payment_method() ] ?? null;
		}
		// Core offline gateway classes describe semantics; no merchant gateway IDs.
		$offline = $gateway instanceof \WC_Gateway_COD || $gateway instanceof \WC_Gateway_BACS || $gateway instanceof \WC_Gateway_Cheque;
		$semantics = apply_filters( 'wcip_payment_method_semantics', $offline ? 'offline' : 'woocommerce', $order, $gateway );
		$confirmed = $order->is_paid() && null !== $order->get_date_paid() && 'offline' !== $semantics;
		return (bool) apply_filters( 'wcip_payment_confirmed', $confirmed, $order, $semantics );
	}

	public function fulfillable( \WC_Order $order ): bool {
		if ( in_array( $order->get_status(), array( 'cancelled', 'failed', 'refunded', 'trash', 'checkout-draft' ), true ) ) { return false; }
		return (bool) apply_filters( 'wcip_fulfillment_eligible', $order->is_paid(), $order );
	}

	public function eligible( \WC_Order $order ): bool {
		$eligible = 'fulfillment' === $this->settings->get( 'automatic_eligibility', 'confirmed_payment' ) ? $this->fulfillable( $order ) : $this->confirmed( $order );
		return $eligible && (bool) apply_filters( 'wcip_automatic_print_eligible', true, $order );
	}
}
