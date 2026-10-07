<?php

namespace WCInvoicePrinter\Admin;

final class PrintConfirmationUi {
	public static function enqueue(): void {
		wp_enqueue_script( 'wcip-confirmation', WCIP_URL . 'assets/js/confirmation.js', array(), WCIP_VERSION, true );
	}

	/** The same confirmation control is used in browser output and admin history. */
	public static function render( array $job_ids ): void {
		echo '<div class="wcip-confirmation"><p>' . esc_html__( 'Confirm only after checking the paper output. Closing the print dialog does not confirm printing.', 'wc-invoice-printer' ) . '</p><button type="button" class="button" data-wcip-confirm data-job-ids="' . esc_attr( implode( ',', array_map( 'intval', $job_ids ) ) ) . '" data-endpoint="' . esc_url( rest_url( 'wc-invoice-printer/v1/printed' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '" data-working="' . esc_attr__( 'Working…', 'wc-invoice-printer' ) . '" data-printed="' . esc_attr__( 'Printed', 'wc-invoice-printer' ) . '" data-success="' . esc_attr__( 'Printing confirmed. A private order note has been added.', 'wc-invoice-printer' ) . '" data-failed="' . esc_attr__( 'The print confirmation could not be saved. Review Print Jobs and try again.', 'wc-invoice-printer' ) . '">' . esc_html__( 'Confirm printed', 'wc-invoice-printer' ) . '</button><p data-wcip-confirm-status role="status" aria-live="polite"></p></div>';
	}
}
