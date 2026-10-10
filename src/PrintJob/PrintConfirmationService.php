<?php

namespace WCInvoicePrinter\PrintJob;

/** Operator confirmation of physical output, separate from provider acceptance. */
final class PrintConfirmationService {
	private PrintJobRepository $jobs;

	public function __construct( PrintJobRepository $jobs ) { $this->jobs = $jobs; }

	public static function eligible( array $job ): bool {
		return in_array( $job['status'], array( JobStatus::SUBMITTED, JobStatus::UNKNOWN ), true );
	}

	/** @throws \RuntimeException with HTTP code 404/409 for invalid confirmation. */
	public function confirm( int $job_id ): array {
		global $wpdb;
		$note_id = 0;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { throw new \RuntimeException( 'The confirmation transaction could not start.' ); }
		try {
			// Serialize repeated/concurrent confirmations of the same job.
			$job = $this->jobs->lock_for_confirmation( $job_id );
			if ( ! $job ) { throw new \RuntimeException( __( 'Print job not found.', 'wc-invoice-printer' ), 404 ); }
			if ( ! empty( $job['printed_at'] ) ) {
				if ( false === $wpdb->query( 'COMMIT' ) ) { throw new \RuntimeException( 'The confirmation transaction could not commit.' ); }
				return $job;
			}
			if ( ! self::eligible( $job ) ) {
				throw new \RuntimeException( __( 'Only prepared or submitted print jobs can be confirmed. Check the paper output first.', 'wc-invoice-printer' ), 409 );
			}
			$order = wc_get_order( (int) $job['order_id'] );
			if ( ! $order instanceof \WC_Order || in_array( $order->get_status(), array( 'trash', 'checkout-draft' ), true ) ) {
				throw new \RuntimeException( __( 'The order no longer exists.', 'wc-invoice-printer' ), 404 );
			}
			$destination = 'browser' === $job['provider_id'] ? __( 'Browser', 'wc-invoice-printer' ) : ( 'cups' === $job['provider_id'] ? 'CUPS' : $job['provider_id'] );
			// translators: 1: print job ID, 2: destination name, 3: number of copies.
			$note = sprintf( __( 'Invoice printed. Job #%1$d; destination: %2$s; copies: %3$d. Confirmed by the operator.', 'wc-invoice-printer' ), $job_id, $destination, (int) $job['copies'] );
			// Private note only. add_order_note does not require order->save().
			if ( 'invoice' !== ( $job['document_type'] ?? 'invoice' ) ) { $note = sprintf( __( 'Document printed: %1$s. Job #%2$d; confirmed by the operator.', 'wc-invoice-printer' ), $job['document_type'], $job_id ); }
			$note_id = (int) $order->add_order_note( $note, false, true );
			if ( $note_id < 1 || ! $this->jobs->record_confirmation( $job_id, $note_id, get_current_user_id() ) ) {
				throw new \RuntimeException( 'The print confirmation could not be saved.' );
			}
			$confirmed = $this->jobs->find( $job_id );
			if ( ! $confirmed || false === $wpdb->query( 'COMMIT' ) ) { throw new \RuntimeException( 'The print confirmation could not commit.' ); }
			return $this->after_confirmation( $confirmed );
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			// Also clean up a note if the comments table does not support transactions.
			if ( $note_id > 0 ) { wp_delete_comment( $note_id, true ); clean_comment_cache( $note_id ); }
			throw $error;
		}
	}
	private function after_confirmation( array $job ): array {
		try { do_action( 'wcip_print_confirmed', $job ); } catch ( \Throwable $error ) {
			// Confirmation is committed. An observer must not erase the paper fact or note.
			try { if ( function_exists( 'wc_get_logger' ) ) { wc_get_logger()->error( 'A print confirmation observer failed.', array( 'source' => 'wc-invoice-printer', 'job_id' => $job['id'] ) ); } } catch ( \Throwable $logging_error ) { /* Committed confirmation remains valid. */ }
		}
		return $job;
	}

}
