<?php

namespace WCInvoicePrinter\PrintJob;

use WCInvoicePrinter\Automation\Scheduler;
use WCInvoicePrinter\Settings\SettingsRepository;
use WCInvoicePrinter\Template\TemplateRegistry;

final class PrintJobService {
	public function __construct(
		private readonly PrintJobRepository $jobs,
		private readonly Scheduler $scheduler,
		private readonly SettingsRepository $settings,
		private readonly TemplateRegistry $templates
	) {}

	public function create_automatic( \WC_Order $order ): ?array {
		if ( ! $this->settings->get( 'automatic_enabled' ) || ! $order->is_paid() ) {
			return null;
		}
		$template_id = (string) $this->settings->get( 'automatic_template', 'classic' );
		$printer_id  = (string) $this->settings->get( 'printnode_printer_id', '' );
		if ( ! $this->templates->has( $template_id ) || '' === $this->settings->api_key() || ! ctype_digit( $printer_id ) || (int) $printer_id < 1 ) {
			return null;
		}
		$eligible = apply_filters( 'wcip_automatic_print_eligible', true, $order );
		if ( ! $eligible ) {
			return null;
		}
		$key      = IdempotencyKey::automatic( $order->get_id(), (string) $order->get_transaction_id() );
		// Older releases included the transaction ID in their key. Honor their
		// existing automatic row when gateways later change that transaction ID.
		$existing = $this->jobs->find_by_key( $key ) ?? $this->jobs->latest_for_order( $order->get_id(), 'automatic' );
		if ( $existing ) {
			// A crash between persisting the job and scheduling its action can leave
			// a queued row behind. Repeated payment callbacks may repair that gap.
			if ( JobStatus::QUEUED->value === $existing['status'] && empty( $existing['action_id'] ) ) {
				$this->scheduler->enqueue( (int) $existing['id'] );
				return $this->jobs->find( (int) $existing['id'] );
			}
			return $existing;
		}
		$job = $this->jobs->create(
			array(
				'order_id'       => $order->get_id(),
				'trigger_type'    => 'automatic',
				'idempotency_key' => $key,
				'template_id'     => $template_id,
				'provider_id'     => 'printnode',
				'printer_id'      => $printer_id,
				'copies'          => (int) $this->settings->get( 'automatic_copies', 1 ),
			)
		);
		if ( JobStatus::QUEUED->value === $job['status'] && empty( $job['action_id'] ) ) {
			$this->scheduler->enqueue( (int) $job['id'] );
		}
		return $this->jobs->find( (int) $job['id'] );
	}

	public function create_manual( \WC_Order $order, string $template_id, string $provider_id, string $printer_id, int $copies ): array {
		if ( ! $this->templates->has( $template_id ) ) {
			throw new \InvalidArgumentException( __( 'Choose a valid invoice template.', 'wc-invoice-printer' ) );
		}
		if ( ! in_array( $provider_id, array( 'browser', 'printnode' ), true ) ) {
			throw new \InvalidArgumentException( __( 'Choose a valid print destination.', 'wc-invoice-printer' ) );
		}
		if ( 'printnode' === $provider_id && ( ! ctype_digit( $printer_id ) || (int) $printer_id < 1 ) ) {
			throw new \InvalidArgumentException( __( 'Choose a valid printer.', 'wc-invoice-printer' ) );
		}
		if ( 'printnode' === $provider_id && '' === $this->settings->api_key() ) {
			throw new \InvalidArgumentException( __( 'Connect PrintNode before printing.', 'wc-invoice-printer' ) );
		}
		if ( $copies < 1 || $copies > 20 ) {
			throw new \InvalidArgumentException( __( 'Choose between 1 and 20 copies.', 'wc-invoice-printer' ) );
		}
		$job = $this->jobs->create( array( 'order_id' => $order->get_id(), 'trigger_type' => 'manual', 'idempotency_key' => IdempotencyKey::manual(), 'template_id' => $template_id, 'provider_id' => $provider_id, 'printer_id' => $printer_id, 'copies' => $copies ) );
		if ( 'printnode' === $provider_id ) {
			$this->scheduler->enqueue( (int) $job['id'] );
		}
		return $this->jobs->find( (int) $job['id'] ) ?? $job;
	}
}
