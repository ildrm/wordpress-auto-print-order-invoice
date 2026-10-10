<?php

namespace WCInvoicePrinter\PrintJob;

use WCInvoicePrinter\Automation\PaymentEligibilityPolicy;
use WCInvoicePrinter\Settings\SettingsRepository;

final class DocumentPrintState {
	private PrintJobRepository $jobs;
	private PaymentEligibilityPolicy $policy;
	public function __construct( PrintJobRepository $jobs, SettingsRepository $settings ) {
		$this->jobs = $jobs;
		$this->policy = new PaymentEligibilityPolicy( $settings );
	}

	public function for_order( \WC_Order $order, string $document_type = 'invoice' ): array {
		$latest = $this->jobs->latest_for_order( $order->get_id(), null, $document_type );
		$confirmed = $this->jobs->latest_confirmed_for_order( $order->get_id(), $document_type );
		$eligible = $this->policy->eligible( $order );
		$state = $confirmed ? 'printed' : ( $latest ? $latest['status'] : ( $eligible ? 'not_printed' : 'ineligible' ) );
		$labels = array(
			'ineligible' => '—', 'not_printed' => __( 'Not printed', 'wc-invoice-printer' ),
			'queued' => __( 'Queued', 'wc-invoice-printer' ), 'processing' => __( 'Printing', 'wc-invoice-printer' ),
			'submitted' => __( 'Awaiting confirmation', 'wc-invoice-printer' ), 'unknown' => __( 'Needs review', 'wc-invoice-printer' ),
			'failed' => __( 'Print failed', 'wc-invoice-printer' ), 'cancelled' => __( 'Cancelled', 'wc-invoice-printer' ),
			'printed' => __( 'Printed', 'wc-invoice-printer' ),
		);
		return array( 'state' => $state, 'label' => $labels[ $state ] ?? $state, 'latest' => $latest, 'confirmed' => $confirmed, 'eligible' => $eligible );
	}
}
