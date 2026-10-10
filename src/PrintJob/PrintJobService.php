<?php

namespace WCInvoicePrinter\PrintJob;

use WCInvoicePrinter\Automation\Scheduler;
use WCInvoicePrinter\Settings\SettingsRepository;
use WCInvoicePrinter\Template\TemplateRegistry;

final class PrintJobService {
	private PrintJobRepository $jobs;
	private Scheduler $scheduler;
	private SettingsRepository $settings;
	private TemplateRegistry $templates;

	public function __construct(
		PrintJobRepository $jobs,
		Scheduler $scheduler,
		SettingsRepository $settings,
		TemplateRegistry $templates
	) {
		$this->jobs = $jobs;
		$this->scheduler = $scheduler;
		$this->settings = $settings;
		$this->templates = $templates;
	}

	public function create_automatic( \WC_Order $order ): ?array {
		if ( ! $this->settings->get( 'automatic_enabled' ) || ! ( new \WCInvoicePrinter\Automation\PaymentEligibilityPolicy( $this->settings ) )->eligible( $order ) ) {
			return null;
		}
		$template_id = (string) $this->settings->get( 'automatic_template', 'classic' );
		$provider_id = (string) $this->settings->get( 'automatic_provider', 'cups' );
		$printer_id  = $this->settings->automatic_printer();
		if ( ! $this->templates->has( $template_id ) || 'invoice' !== $this->templates->get( $template_id )->document_type || ! $this->settings->automatic_ready() ) {
			return null;
		}
		$key      = IdempotencyKey::automatic( $order->get_id(), (string) $order->get_transaction_id() );
		// Older releases included the transaction ID in their key. Honor their
		// existing automatic row when gateways later change that transaction ID.
		$existing = $this->jobs->find_by_key( $key ) ?? $this->jobs->latest_for_order( $order->get_id(), 'automatic' );
		if ( $existing ) {
			// A crash between persisting the job and scheduling its action can leave
			// a queued row behind. Repeated payment callbacks may repair that gap.
			if ( 'cups' === $existing['provider_id'] && JobStatus::QUEUED === $existing['status'] && empty( $existing['action_id'] ) ) {
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
				'provider_id'     => $provider_id,
				'printer_id'      => $printer_id,
				'copies'          => (int) $this->settings->get( 'automatic_copies', 1 ),
			)
		);
		if ( 'cups' === $job['provider_id'] && JobStatus::QUEUED === $job['status'] && empty( $job['action_id'] ) ) {
			$this->scheduler->enqueue( (int) $job['id'] );
		}
		return $this->jobs->find( (int) $job['id'] );
	}

	public function create_manual( \WC_Order $order, string $template_id, string $provider_id, string $printer_id, int $copies ): array {
		if ( ! $this->templates->has( $template_id ) ) {
			throw new \InvalidArgumentException( __( 'Choose a valid invoice template.', 'wc-invoice-printer' ) );
		}
		if ( ! in_array( $provider_id, array( 'browser', 'cups', 'agent' ), true ) ) {
			throw new \InvalidArgumentException( __( 'Choose a valid print destination.', 'wc-invoice-printer' ) );
		}
		if ( 'cups' === $provider_id && ! \WCInvoicePrinter\Printing\Cups\CupsProvider::valid_printer( $printer_id ) ) {
			throw new \InvalidArgumentException( __( 'Choose a valid printer.', 'wc-invoice-printer' ) );
		}
		if ( 'cups' === $provider_id && '' === $this->settings->cups_endpoint() ) {
			throw new \InvalidArgumentException( __( 'Connect CUPS before printing.', 'wc-invoice-printer' ) );
		}
		if ( 'agent' === $provider_id && ( ! $this->settings->agent_ready() || $printer_id !== $this->settings->get( 'agent_queue' ) ) ) { throw new \InvalidArgumentException( 'Configure the paired print agent route before printing.' ); }
		if ( $copies < 1 || $copies > 20 ) {
			throw new \InvalidArgumentException( __( 'Choose between 1 and 20 copies.', 'wc-invoice-printer' ) );
		}
		$document_type = $this->templates->get( $template_id )->document_type;
		if ( 'shipping_label' === $document_type && ! ( new \WCInvoicePrinter\Invoice\RecipientResolver( $this->settings ) )->resolve( $order )['requires_shipping'] && ! apply_filters( 'wcip_allow_nonshipping_label', false, $order ) ) { throw new \InvalidArgumentException( __( 'This order does not require a shipping label.', 'wc-invoice-printer' ) ); }
		$job = $this->jobs->create( array( 'document_type' => $document_type, 'order_id' => $order->get_id(), 'trigger_type' => 'manual', 'idempotency_key' => IdempotencyKey::manual(), 'template_id' => $template_id, 'provider_id' => $provider_id, 'printer_id' => $printer_id, 'copies' => $copies ) );
		if ( 'cups' === $provider_id ) {
			$this->scheduler->enqueue( (int) $job['id'] );
		}
		return $this->jobs->find( (int) $job['id'] ) ?? $job;
	}
}
