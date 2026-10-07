<?php

namespace WCInvoicePrinter\Invoice;

use WCInvoicePrinter\Settings\SettingsRepository;

final class InvoiceFactory {
	private SettingsRepository $settings;

	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	public function from_order( \WC_Order $order ): InvoiceData {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$items[] = array(
				'name'       => $item->get_name(),
				'variation'  => $this->variation_text( $item ),
				'sku'        => $product ? $product->get_sku() : '',
				'quantity'   => $item->get_quantity(),
				// Keep unit/subtotal/discount exclusive of tax; total is the amount
				// actually charged for this line after discounts, including line tax.
				'unit_price' => $this->price( $order->get_item_subtotal( $item, false, false ), $order ),
				'subtotal'   => $this->price( $item->get_subtotal(), $order ),
				'discount'   => $this->price( max( 0, (float) $item->get_subtotal() - (float) $item->get_total() ), $order ),
				'tax'        => $this->price( $item->get_total_tax(), $order ),
				'total'      => $this->price( (float) $item->get_total() + (float) $item->get_total_tax(), $order ),
			);
		}

		$totals = array();
		foreach ( $order->get_order_item_totals() as $total ) {
			$totals[] = array(
				'label' => wp_strip_all_tags( (string) $total['label'] ),
				'value' => wp_kses( (string) $total['value'], $this->price_tags() ),
			);
		}

		$data = new InvoiceData(
			array(
				'id'             => $order->get_id(),
				'number'         => $order->get_order_number(),
				'date'           => $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '',
				'paid_date'      => $order->get_date_paid() ? wc_format_datetime( $order->get_date_paid() ) : '',
				'currency'       => $order->get_currency(),
				'status'         => wc_get_order_status_name( $order->get_status() ),
			),
			array(
				'name'       => (string) $this->settings->get( 'business_name' ),
				'details'    => (string) $this->settings->get( 'business_details' ),
				'phone'      => (string) $this->settings->get( 'business_phone' ),
				'email'      => (string) $this->settings->get( 'business_email' ),
				'logo_data_uri' => $this->safe_logo_data_uri( (string) $this->settings->get( 'logo_url' ) ),
				'address'    => $this->store_address(),
			),
			array(
				'name'             => $order->get_formatted_billing_full_name(),
				'company'          => $order->get_billing_company(),
				'billing_address'  => $this->plain_address( $order->get_formatted_billing_address() ),
				'shipping_address' => $this->plain_address( $order->get_formatted_shipping_address() ),
				'phone'            => $order->get_billing_phone(),
				'email'            => $order->get_billing_email(),
			),
			$items,
			$totals,
			array(
				'payment_method' => $order->get_payment_method_title(),
				'shipping_method'=> $order->get_shipping_method(),
				'note'           => $this->settings->get( 'show_customer_note' ) ? $order->get_customer_note() : '',
			),
			is_rtl()
		);
		return apply_filters( 'wcip_invoice_data', $data, $order );
	}

	public function sample( bool $rtl = false ): InvoiceData {
		return new InvoiceData(
			array( 'id' => 1042, 'number' => '1042', 'date' => __( 'September 15, 2026', 'wc-invoice-printer' ), 'paid_date' => __( 'September 15, 2026', 'wc-invoice-printer' ), 'currency' => 'USD', 'status' => __( 'Processing', 'wc-invoice-printer' ) ),
			array( 'name' => __( 'Northstar Supply Co.', 'wc-invoice-printer' ), 'details' => __( 'Business registration and tax details', 'wc-invoice-printer' ), 'phone' => '+1 555 0142', 'email' => 'billing@example.com', 'logo_data_uri' => '', 'address' => __( '24 Market Street, Portland, OR', 'wc-invoice-printer' ) ),
			array( 'name' => __( 'Alex Morgan', 'wc-invoice-printer' ), 'company' => __( 'Morgan Studio', 'wc-invoice-printer' ), 'billing_address' => __( '840 Evergreen Terrace, Seattle, WA', 'wc-invoice-printer' ), 'shipping_address' => '', 'phone' => '+1 555 0199', 'email' => 'alex@example.com' ),
			array(
				array( 'name' => __( 'Professional planning notebook with an intentionally long product name', 'wc-invoice-printer' ), 'variation' => __( 'Color: Midnight / Size: Large', 'wc-invoice-printer' ), 'sku' => 'PLAN-XL-01', 'quantity' => 2, 'unit_price' => '$42.00', 'subtotal' => '$84.00', 'discount' => '$8.40', 'tax' => '$6.05', 'total' => '$81.65' ),
				array( 'name' => __( 'Fine gel pen set', 'wc-invoice-printer' ), 'variation' => '', 'sku' => 'PEN-06', 'quantity' => 1, 'unit_price' => '$18.00', 'subtotal' => '$18.00', 'discount' => '$0.00', 'tax' => '$1.44', 'total' => '$19.44' ),
			),
			array( array( 'label' => __( 'Subtotal:', 'wc-invoice-printer' ), 'value' => '$102.00' ), array( 'label' => __( 'Discount:', 'wc-invoice-printer' ), 'value' => '−$8.40' ), array( 'label' => __( 'Shipping:', 'wc-invoice-printer' ), 'value' => '$7.00' ), array( 'label' => __( 'Tax:', 'wc-invoice-printer' ), 'value' => '$7.49' ), array( 'label' => __( 'Total:', 'wc-invoice-printer' ), 'value' => '<strong>$108.09</strong>' ) ),
			array( 'payment_method' => __( 'Credit card', 'wc-invoice-printer' ), 'shipping_method' => __( 'Ground shipping', 'wc-invoice-printer' ), 'note' => __( 'Please leave the parcel at reception.', 'wc-invoice-printer' ) ),
			$rtl
		);
	}

	/** @param float|string $amount */
	private function price( $amount, \WC_Order $order ): string {
		return wp_kses( wc_price( $amount, array( 'currency' => $order->get_currency() ) ), $this->price_tags() );
	}

	private function store_address(): string {
		$parts = array_filter( array( get_option( 'woocommerce_store_address' ), get_option( 'woocommerce_store_address_2' ), get_option( 'woocommerce_store_city' ), get_option( 'woocommerce_store_postcode' ) ) );
		return implode( ', ', array_map( 'sanitize_text_field', $parts ) );
	}

	private function variation_text( \WC_Order_Item_Product $item ): string {
		$parts = array();
		foreach ( $item->get_formatted_meta_data() as $meta ) {
			$parts[] = html_entity_decode( wp_strip_all_tags( $meta->display_key . ': ' . $meta->display_value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return implode( ' · ', $parts );
	}

	private function price_tags(): array {
		return array( 'span' => array( 'class' => true ), 'bdi' => array(), 'small' => array( 'class' => true ), 'strong' => array(), 'del' => array(), 'ins' => array() );
	}

	private function plain_address( string $address ): string {
		// WooCommerce joins address lines with <br>; removing tags directly
		// concatenates the name, street and locality into an unusable address.
		return trim( html_entity_decode( wp_strip_all_tags( (string) preg_replace( '/<br\s*\/?\s*>/i', "\n", $address ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	private function safe_logo_data_uri( string $url ): string {
		if ( '' === $url || ! wp_http_validate_url( $url ) ) { return ''; }
		$key    = 'wcip_logo_' . hash( 'sha256', $url );
		$cached = get_transient( $key );
		if ( is_string( $cached ) ) { return $cached; }
		$response = wp_safe_remote_get( $url, array( 'timeout' => 8, 'redirection' => 2, 'limit_response_size' => 2 * MB_IN_BYTES ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return ''; }
		$mime = strtolower( trim( strtok( wp_remote_retrieve_header( $response, 'content-type' ), ';' ) ?: '' ) );
		if ( ! in_array( $mime, array( 'image/png', 'image/jpeg', 'image/gif', 'image/webp' ), true ) ) { return ''; }
		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body || strlen( $body ) > 2 * MB_IN_BYTES ) { return ''; }
		$image = @getimagesizefromstring( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid remote image data is an expected failure.
		if ( ! is_array( $image ) || $image[0] > 5000 || $image[1] > 5000 || ( $image['mime'] ?? '' ) !== $mime ) { return ''; }
		$data_uri = 'data:' . $mime . ';base64,' . base64_encode( $body );
		set_transient( $key, $data_uri, DAY_IN_SECONDS );
		return $data_uri;
	}
}
