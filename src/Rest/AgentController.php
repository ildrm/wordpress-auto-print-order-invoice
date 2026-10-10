<?php

namespace WCInvoicePrinter\Rest;

use WCInvoicePrinter\Automation\PaymentEligibilityPolicy;
use WCInvoicePrinter\I18n\Locale;
use WCInvoicePrinter\Printing\Agent\AgentQueue;
use WCInvoicePrinter\PrintJob\PrintJobRepository;
use WCInvoicePrinter\Settings\SettingsRepository;

/** Pull delivery for a paired workstation, authenticated by WordPress Application Password. */
final class AgentController {
	private SettingsRepository $settings;
	private PrintJobRepository $jobs;
	private AgentQueue $queue;
	private $invoices; private $html; private $pdf; private $templates; private $reconciler;
	public function __construct( $settings, $jobs, $invoices, $html, $pdf, $templates, $reconciler ) {
		$this->settings = $settings; $this->jobs = $jobs; $this->queue = new AgentQueue();
		$this->invoices = $invoices; $this->html = $html; $this->pdf = $pdf; $this->templates = $templates; $this->reconciler = $reconciler;
	}
	public function allowed(): bool {
		return is_ssl() && rest_get_authenticated_app_password() && get_current_user_id() > 0 && get_current_user_id() === (int) $this->settings->get( 'agent_user_id' ) && current_user_can( 'wcip_run_print_agent' ) && $this->settings->agent_ready();
	}
	public function register(): void {
		$permission = array( $this, 'allowed' ); $ns = RestController::NS;
		register_rest_route( $ns, '/agent/claim', array( 'methods' => 'POST', 'callback' => array( $this, 'claim' ), 'permission_callback' => $permission, 'args' => array( 'route' => array( 'required' => true, 'type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9_.-]{0,126}$' ) ) ) );
		$args = array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ), 'token' => array( 'required' => true, 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ) );
		register_rest_route( $ns, '/agent/jobs/(?P<id>\d+)/start', array( 'methods' => 'POST', 'callback' => array( $this, 'start' ), 'permission_callback' => $permission, 'args' => $args ) );
		$args['outcome'] = array( 'required' => true, 'type' => 'string', 'enum' => array( 'submitted', 'unknown', 'failed' ) );
		register_rest_route( $ns, '/agent/jobs/(?P<id>\d+)/receipt', array( 'methods' => 'POST', 'callback' => array( $this, 'receipt' ), 'permission_callback' => $permission, 'args' => $args ) );
	}
	public function claim( \WP_REST_Request $request ) {
		if ( ! $this->allowed() || $request['route'] !== $this->settings->get( 'agent_queue' ) ) { return $this->error( 403 ); }
		if ( get_transient( 'wcip_agent_poll_' . get_current_user_id() ) ) { return $this->error( 429 ); }
		set_transient( 'wcip_agent_poll_' . get_current_user_id(), true, 2 );
		// A workstation poll provides a runner even where WP-Cron/loopback is disabled.
		if ( 'agent' === $this->settings->get( 'automatic_provider' ) && ! get_transient( 'wcip_agent_reconcile' ) ) {
			set_transient( 'wcip_agent_reconcile', true, (int) $this->settings->get( 'reconciliation_interval', 600 ) ); $this->reconciler->run();
		}
		$route = (string) $request['route']; $this->queue->recover( $route );
		update_option( 'wcip_agent_last_seen', time(), false );
		foreach ( $this->queue->candidates( $route ) as $job ) {
			$id = (int) $job['id']; $token = bin2hex( random_bytes( 32 ) );
			if ( ! $this->queue->claim( $id, $route, get_current_user_id(), $token ) ) { continue; }
			$switched = switch_to_locale( Locale::site_locale() );
			try {
				$order = wc_get_order( (int) $job['order_id'] );
				if ( ! $this->eligible( $job, $order ) ) { throw new \RuntimeException( 'Order is no longer eligible.' ); }
				$template = $this->templates->get( $job['template_id'] );
				$bytes = $this->pdf->render( $this->html->render( $this->invoices->from_order( $order ), $template->id ), $template );
				if ( strlen( $bytes ) > 20 * 1024 * 1024 || 0 !== strpos( $bytes, '%PDF-' ) ) { throw new \RuntimeException( 'Invalid PDF.' ); }
				return $this->response( array( 'job' => array( 'id' => $id, 'token' => $token, 'route' => $route, 'copies' => (int) $job['copies'], 'sha256' => hash( 'sha256', $bytes ), 'pdf_base64' => base64_encode( $bytes ) ) ) );
			} catch ( \Throwable $error ) {
				$this->queue->reject_prepared( $id, get_current_user_id(), $token );
			} finally { if ( $switched ) { restore_previous_locale(); } }
		}
		return $this->response( array( 'job' => null ) );
	}
	public function start( \WP_REST_Request $request ) {
		$job = $this->owned( $request );
		if ( ! $job ) { return $this->error( 403 ); }
		if ( ! $this->eligible( $job, wc_get_order( (int) $job['order_id'] ) ) ) {
			$this->queue->reject_prepared( (int) $job['id'], get_current_user_id(), $request['token'] ); return $this->error( 409 );
		}
		if ( ! $this->queue->start( (int) $job['id'], get_current_user_id(), $request['token'] ) ) { return $this->error( 409 ); }
		return $this->response( array( 'start' => true ) );
	}
	public function receipt( \WP_REST_Request $request ) {
		$job = $this->owned( $request ); $outcome = $request['outcome'];
		if ( ! $job || ! in_array( $outcome, array( 'submitted', 'unknown', 'failed' ), true ) ) { return $this->error( 403 ); }
		if ( 'done' === $job['agent_phase'] ) { return $job['status'] === $outcome ? $this->response( array( 'recorded' => true ) ) : $this->error( 409 ); }
		if ( ! $this->queue->receipt( (int) $job['id'], get_current_user_id(), $request['token'], $outcome ) ) { return $this->error( 409 ); }
		if ( 'submitted' === $outcome ) { do_action( 'wcip_after_print_submission', (int) $job['id'], 'agent:' . $job['id'] ); }
		return $this->response( array( 'recorded' => true ) );
	}
	private function owned( \WP_REST_Request $request ): ?array {
		if ( ! $this->allowed() || ! is_string( $request['token'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $request['token'] ) ) { return null; }
		$job = $this->jobs->find( (int) $request['id'] );
		return $job && 'agent' === $job['provider_id'] && $job['printer_id'] === $this->settings->get( 'agent_queue' ) && (int) $job['agent_user'] === get_current_user_id() && hash_equals( (string) $job['agent_token'], hash( 'sha256', $request['token'] ) ) ? $job : null;
	}
	private function eligible( array $job, $order ): bool { return $order instanceof \WC_Order && ( 'automatic' !== $job['trigger_type'] || ( $this->settings->get( 'automatic_enabled' ) && ( new PaymentEligibilityPolicy( $this->settings ) )->eligible( $order ) ) ); }
	private function response( array $data ) { $response = new \WP_REST_Response( $data ); $response->header( 'Cache-Control', 'no-store, private' ); return $response; }
	private function error( int $status ) { return new \WP_Error( 'wcip_agent_request', 'Print agent request refused. Check pairing, lease and authentication.', array( 'status' => $status ) ); }
}
