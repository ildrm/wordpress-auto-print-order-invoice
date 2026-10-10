<?php

namespace WCInvoicePrinter\Invoice;

/** Stored weight wins; catalog fallback is explicitly identified and may change. */
final class WeightCalculator {
	public function line( $item, float $quantity ): array {
		$weight = method_exists( $item, 'get_meta' ) ? $item->get_meta( '_wcip_unit_weight_kg', true ) : '';
		$source = 'order_item';
		if ( '' === $weight || null === $weight ) {
			$product = $item->get_product();
			$weight = $product && method_exists( $product, 'get_weight' ) ? $product->get_weight( 'edit' ) : '';
			if ( '' === $weight && $product && method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() && apply_filters( 'wcip_weight_parent_fallback', true, $item ) ) {
				$parent = wc_get_product( $product->get_parent_id() );
				$weight = $parent ? $parent->get_weight() : '';
			}
			$source = 'current_catalog';
			if ( '' !== $weight && is_numeric( $weight ) && function_exists( 'wc_get_weight' ) ) { $weight = wc_get_weight( $weight, 'kg', get_option( 'woocommerce_weight_unit', 'kg' ) ); }
		}
		$known = is_numeric( $weight ) && (float) $weight >= 0 && is_finite( (float) $weight );
		$data = array( 'unit_kg' => $known ? (float) $weight : null, 'line_kg' => $known ? round( (float) $weight * max( 0, $quantity ), 6 ) : null, 'source' => $known ? $source : 'unknown' );
		return apply_filters( 'wcip_item_weight', $data, $item, $quantity );
	}
}
