<?php
/**
 * Plugin Name: WCIP Customization
 * Description: Example custom invoice template and purchase order field.
 * Requires Plugins: wc-invoice-printer
 * Requires PHP: 7.4
 * Text Domain: wcip-customization
 * License: GPL-2.0-or-later
 */
defined( 'ABSPATH' ) || exit;

add_filter( 'wcip_invoice_templates', static function ( array $templates ): array {
	$templates['custom-classic'] = new \WCInvoicePrinter\Template\TemplateDefinition(
		'custom-classic',
		__( 'Custom classic', 'wcip-customization' ),
		__( 'Store invoice layout with a purchase order reference.', 'wcip-customization' ),
		'A4', 'portrait', true,
		__DIR__ . '/templates/invoice.php'
	);
	return $templates;
} );

add_filter( 'wcip_invoice_data', static function ( $invoice, $order ) {
	$fields = $invoice->order;
	$fields['purchase_order'] = sanitize_text_field( (string) $order->get_meta( '_purchase_order', true ) );
	return new \WCInvoicePrinter\Invoice\InvoiceData(
		$fields, $invoice->store, $invoice->customer, $invoice->items,
		$invoice->totals, $invoice->fulfillment, $invoice->rtl
	);
}, 10, 2 );
