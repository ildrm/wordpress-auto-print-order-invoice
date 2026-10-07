<?php

namespace WCInvoicePrinter\Admin;

use WCInvoicePrinter\PrintJob\JobStatus;
use WCInvoicePrinter\PrintJob\PrintJobRepository;
use WCInvoicePrinter\Settings\SettingsRepository;
use WCInvoicePrinter\Template\TemplateRegistry;

final class AdminPage {
	private const SLUG = 'wc-invoice-printer';

	private SettingsRepository $settings;
	private TemplateRegistry $templates;
	private PrintJobRepository $jobs;

	public function __construct( SettingsRepository $settings, TemplateRegistry $templates, PrintJobRepository $jobs ) {
		$this->settings = $settings;
		$this->templates = $templates;
		$this->jobs = $jobs;
	}

	public function register_menu(): void {
		add_submenu_page( 'woocommerce', __( 'Invoice Printer', 'wc-invoice-printer' ), __( 'Invoice Printer', 'wc-invoice-printer' ), 'wcip_print_invoices', self::SLUG, array( $this, 'render' ) );
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'woocommerce_page_' . self::SLUG !== $hook ) { return; }
		wp_enqueue_style( 'wcip-admin', WCIP_URL . 'assets/css/admin.css', array(), WCIP_VERSION );
		wp_enqueue_script( 'wcip-admin', WCIP_URL . 'assets/js/admin.js', array( 'wp-api-fetch' ), WCIP_VERSION, true );
		wp_localize_script( 'wcip-admin', 'wcipAdmin', array( 'restUrl' => esc_url_raw( rest_url( 'wc-invoice-printer/v1' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'printerId' => (string) $this->settings->get( 'printnode_printer_id', '' ), 'strings' => array( 'working' => __( 'Working…', 'wc-invoice-printer' ), 'invalidCopies' => __( 'Choose between 1 and 20 copies.', 'wc-invoice-printer' ), 'failed' => __( 'Something went wrong. Check the details and try again.', 'wc-invoice-printer' ), 'selectPrinter' => __( 'Choose a printer', 'wc-invoice-printer' ), 'noPrinters' => __( 'No printers were found. Confirm that the PrintNode client is online.', 'wc-invoice-printer' ), 'submitted' => __( 'Submitted to PrintNode.', 'wc-invoice-printer' ), 'connectionOk' => __( 'PrintNode connection verified.', 'wc-invoice-printer' ), 'printersFound' => __( '%d printer(s) found.', 'wc-invoice-printer' ), 'jobsQueued' => __( '%d invoice(s) queued.', 'wc-invoice-printer' ) ) ) );
	}

	public function save(): void {
		if ( ! current_user_can( 'wcip_manage_settings' ) ) { wp_die( esc_html__( 'You are not allowed to change these settings.', 'wc-invoice-printer' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'wcip_save_settings' );
		foreach ( $_POST as $value ) {
			if ( ! is_scalar( $value ) ) { wp_die( esc_html__( 'Invalid settings input.', 'wc-invoice-printer' ), '', array( 'response' => 400 ) ); }
		}
		$section = sanitize_key( $this->request_value( $_POST, 'section', 'general' ) );
		if ( ! in_array( $section, array( 'general', 'templates', 'automatic', 'printers' ), true ) ) { wp_die( esc_html__( 'Invalid settings section.', 'wc-invoice-printer' ), '', array( 'response' => 400 ) ); }
		$changes = array();
		$setup_required = false;
		if ( 'general' === $section ) {
			$template = sanitize_key( $this->request_value( $_POST, 'default_template', 'classic' ) );
			$changes  = array( 'business_name' => sanitize_text_field( $this->request_value( $_POST, 'business_name' ) ), 'business_details' => sanitize_textarea_field( $this->request_value( $_POST, 'business_details' ) ), 'business_phone' => sanitize_text_field( $this->request_value( $_POST, 'business_phone' ) ), 'business_email' => sanitize_email( $this->request_value( $_POST, 'business_email' ) ), 'logo_url' => esc_url_raw( $this->request_value( $_POST, 'logo_url' ) ), 'default_template' => $this->templates->has( $template ) ? $template : 'classic', 'show_customer_note' => '1' === $this->request_value( $_POST, 'show_customer_note' ) );
		} elseif ( 'templates' === $section ) {
			$template = sanitize_key( $this->request_value( $_POST, 'default_template' ) );
			if ( $this->templates->has( $template ) ) { $changes['default_template'] = $template; }
		} elseif ( 'automatic' === $section ) {
			$template = sanitize_key( $this->request_value( $_POST, 'automatic_template' ) );
			$requested_enabled = '1' === $this->request_value( $_POST, 'automatic_enabled' );
			$changes  = array( 'automatic_enabled' => $requested_enabled && '' !== $this->settings->api_key() && ctype_digit( (string) $this->settings->get( 'printnode_printer_id', '' ) ), 'automatic_template' => $this->templates->has( $template ) ? $template : 'classic', 'automatic_copies' => max( 1, min( 20, (int) $this->request_value( $_POST, 'automatic_copies', '1' ) ) ) );
			$setup_required = $requested_enabled && ! $changes['automatic_enabled'];
		} elseif ( 'printers' === $section ) {
			$changes = array( 'printnode_printer_id' => $this->request_value( $_POST, 'printnode_printer_id' ), 'printnode_printer_name' => sanitize_text_field( $this->request_value( $_POST, 'printnode_printer_name' ) ) );
			if ( ! $this->settings->api_key_is_external() && '' !== $this->request_value( $_POST, 'printnode_api_key' ) ) { $changes['printnode_api_key'] = $this->request_value( $_POST, 'printnode_api_key' ); }
		}
		$this->settings->update( $changes );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => $section, 'updated' => '1', 'setup_required' => $setup_required ? '1' : false ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render(): void {
		$tab = sanitize_key( $this->request_value( $_GET, 'tab', 'general' ) );
		$tabs = array( 'general' => __( 'General', 'wc-invoice-printer' ), 'templates' => __( 'Templates', 'wc-invoice-printer' ), 'automatic' => __( 'Automatic Printing', 'wc-invoice-printer' ), 'printers' => __( 'Printers', 'wc-invoice-printer' ), 'jobs' => __( 'Print Jobs', 'wc-invoice-printer' ) );
		if ( 'bulk' !== $tab && ! isset( $tabs[ $tab ] ) ) { $tab = 'general'; }
		if ( in_array( $tab, array( 'general', 'templates', 'automatic', 'printers' ), true ) && ! current_user_can( 'wcip_manage_settings' ) ) { $tab = 'jobs'; }
		echo '<div class="wrap wcip-admin"><header class="wcip-page-header"><div><h1>' . esc_html__( 'Invoice Printer', 'wc-invoice-printer' ) . '</h1><p>' . esc_html__( 'Create consistent invoices and send them to the right printer.', 'wc-invoice-printer' ) . '</p></div></header>';
		if ( isset( $_GET['updated'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'wc-invoice-printer' ) . '</p></div>'; }
		if ( isset( $_GET['setup_required'] ) ) { echo '<div class="notice notice-warning"><p>' . esc_html__( 'Automatic printing remains disabled until a PrintNode credential and printer are configured.', 'wc-invoice-printer' ) . '</p></div>'; }
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Invoice Printer sections', 'wc-invoice-printer' ) . '">';
		foreach ( $tabs as $id => $label ) { if ( current_user_can( 'wcip_manage_settings' ) || 'jobs' === $id ) { echo '<a class="nav-tab ' . ( $tab === $id ? 'nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => $id ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a>'; } }
		echo '</nav><main class="wcip-content">';
		switch ( $tab ) {
			case 'templates': $this->templates_screen(); break;
			case 'automatic': $this->automatic_screen(); break;
			case 'printers': $this->printers_screen(); break;
			case 'jobs': $this->jobs_screen(); break;
			case 'bulk': $this->bulk_screen(); break;
			default: $this->general_screen();
		}
		echo '</main></div>';
	}

	private function form_start( string $section ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wcip-form"><input type="hidden" name="action" value="wcip_save_settings"><input type="hidden" name="section" value="' . esc_attr( $section ) . '">';
		wp_nonce_field( 'wcip_save_settings' );
	}

	private function general_screen(): void {
		$s = $this->settings->all(); $this->form_start( 'general' );
		echo '<section><h2>' . esc_html__( 'Invoice identity', 'wc-invoice-printer' ) . '</h2><p class="description">' . esc_html__( 'Business details shown to customers on every invoice.', 'wc-invoice-printer' ) . '</p><div class="wcip-fields">';
		$this->field( 'business_name', __( 'Business name', 'wc-invoice-printer' ), $s['business_name'], 'text' ); $this->field( 'logo_url', __( 'Logo URL', 'wc-invoice-printer' ), $s['logo_url'], 'url' ); $this->field( 'business_phone', __( 'Phone', 'wc-invoice-printer' ), $s['business_phone'], 'text' ); $this->field( 'business_email', __( 'Email', 'wc-invoice-printer' ), $s['business_email'], 'email' ); $this->textarea( 'business_details', __( 'Business and tax details', 'wc-invoice-printer' ), $s['business_details'] );
		echo '</div></section><section><h2>' . esc_html__( 'Manual printing defaults', 'wc-invoice-printer' ) . '</h2>'; $this->template_select( 'default_template', $s['default_template'] ); echo '<label class="wcip-check"><input type="checkbox" name="show_customer_note" value="1" ' . checked( $s['show_customer_note'], true, false ) . '> ' . esc_html__( 'Include customer notes on invoices', 'wc-invoice-printer' ) . '</label></section>'; submit_button(); echo '</form>';
	}

	private function templates_screen(): void {
		$selected = (string) $this->settings->get( 'default_template' ); $this->form_start( 'templates' ); echo '<div class="wcip-section-heading"><div><h2>' . esc_html__( 'Choose an invoice layout', 'wc-invoice-printer' ) . '</h2><p>' . esc_html__( 'Preview uses realistic sample data and can switch to RTL.', 'wc-invoice-printer' ) . '</p></div></div><div class="wcip-template-grid">';
		foreach ( $this->templates->all() as $template ) { $preview = wp_nonce_url( add_query_arg( array( 'action' => 'wcip_preview', 'sample' => 1, 'template' => $template->id ), admin_url( 'admin-post.php' ) ), 'wcip_preview_invoices' ); echo '<article class="wcip-template-card ' . ( $selected === $template->id ? 'is-selected' : '' ) . '"><div class="wcip-template-thumb wcip-thumb-' . esc_attr( $template->id ) . '" aria-hidden="true"><span></span><i></i><i></i><i></i></div><div class="wcip-template-body"><div class="wcip-template-title"><h3>' . esc_html( $template->name ) . '</h3>' . ( $selected === $template->id ? '<span class="wcip-selected">✓ ' . esc_html__( 'Selected', 'wc-invoice-printer' ) . '</span>' : '' ) . '</div><p>' . esc_html( $template->description ) . '</p><span class="wcip-paper">' . esc_html( $template->paper_size ) . '</span><div class="wcip-card-actions"><a class="button" target="_blank" rel="noopener" href="' . esc_url( $preview ) . '">' . esc_html__( 'Preview', 'wc-invoice-printer' ) . '</a><a class="button" target="_blank" rel="noopener" href="' . esc_url( add_query_arg( 'rtl', 1, $preview ) ) . '">' . esc_html__( 'RTL preview', 'wc-invoice-printer' ) . '</a><label class="button button-primary"><input class="screen-reader-text" type="radio" name="default_template" value="' . esc_attr( $template->id ) . '" ' . checked( $selected, $template->id, false ) . '>' . esc_html__( 'Select', 'wc-invoice-printer' ) . '</label></div></div></article>'; }
		echo '</div>'; submit_button( __( 'Save selected template', 'wc-invoice-printer' ) ); echo '</form>';
	}

	private function automatic_screen(): void {
		$s = $this->settings->all(); $this->form_start( 'automatic' ); echo '<section><div class="wcip-toggle-row"><div><h2>' . esc_html__( 'Automatic printing', 'wc-invoice-printer' ) . '</h2><p>' . esc_html__( 'After verified payment, queue an invoice without delaying checkout.', 'wc-invoice-printer' ) . '</p></div><label class="wcip-switch"><input id="wcip-auto-enabled" type="checkbox" name="automatic_enabled" value="1" ' . checked( $s['automatic_enabled'], true, false ) . '><span>' . esc_html__( 'Enabled', 'wc-invoice-printer' ) . '</span></label></div><div class="wcip-dependent" data-auto-settings><ol class="wcip-setup"><li><strong>' . esc_html__( 'Provider', 'wc-invoice-printer' ) . '</strong><span>PrintNode</span></li><li><strong>' . esc_html__( 'Connection', 'wc-invoice-printer' ) . '</strong><span>' . ( $this->settings->api_key() ? esc_html__( 'Credential configured', 'wc-invoice-printer' ) : esc_html__( 'Not configured', 'wc-invoice-printer' ) ) . '</span></li><li><strong>' . esc_html__( 'Printer', 'wc-invoice-printer' ) . '</strong><span>' . esc_html( $s['printnode_printer_name'] ?: __( 'No printer selected', 'wc-invoice-printer' ) ) . '</span></li></ol><div class="wcip-fields">'; $this->template_select( 'automatic_template', $s['automatic_template'] ); $this->field( 'automatic_copies', __( 'Copies', 'wc-invoice-printer' ), $s['automatic_copies'], 'number', 'min="1" max="20"' ); echo '<div class="wcip-field"><span class="wcip-label">' . esc_html__( 'Trigger', 'wc-invoice-printer' ) . '</span><strong>' . esc_html__( 'Payment completed', 'wc-invoice-printer' ) . '</strong><p class="description">' . esc_html__( 'Only orders WooCommerce confirms as paid are eligible.', 'wc-invoice-printer' ) . '</p></div></div></div></section>'; submit_button(); echo '</form>';
	}

	private function printers_screen(): void {
		$s = $this->settings->all(); $external = $this->settings->api_key_is_external(); $configured = '' !== $this->settings->api_key(); $this->form_start( 'printers' ); echo '<section><h2>' . esc_html__( 'PrintNode connection', 'wc-invoice-printer' ) . '</h2><p>' . esc_html__( 'Invoice PDFs contain customer data and are sent to PrintNode when this destination is used.', 'wc-invoice-printer' ) . '</p><div class="wcip-connection ' . ( $configured ? 'is-connected' : '' ) . '"><span class="wcip-status-dot" aria-hidden="true"></span><div><strong>' . ( $configured ? esc_html__( 'Credential configured', 'wc-invoice-printer' ) : esc_html__( 'Not connected', 'wc-invoice-printer' ) ) . '</strong><p>' . ( $external ? esc_html__( 'Managed externally with WCIP_PRINTNODE_API_KEY; it cannot be edited here.', 'wc-invoice-printer' ) : esc_html__( 'The stored key is never displayed or returned to the browser.', 'wc-invoice-printer' ) ) . '</p></div></div>';
		if ( ! $external ) { $this->field( 'printnode_api_key', $configured ? __( 'Replace API key', 'wc-invoice-printer' ) : __( 'API key', 'wc-invoice-printer' ), '', 'password', 'autocomplete="new-password"' ); echo '<p class="description">' . esc_html__( 'Save a new key before testing the connection. Leave the field blank to retain the existing credential.', 'wc-invoice-printer' ) . '</p>'; }
		echo '<div class="wcip-actions"><button type="button" class="button" data-wcip-action="test-connection">' . esc_html__( 'Test connection', 'wc-invoice-printer' ) . '</button><button type="button" class="button" data-wcip-action="refresh-printers">' . esc_html__( 'Refresh printers', 'wc-invoice-printer' ) . '</button></div><div class="wcip-async-notice" role="status" aria-live="polite"></div></section><section><h2>' . esc_html__( 'Printer', 'wc-invoice-printer' ) . '</h2><label for="wcip-printer-select" class="wcip-label">' . esc_html__( 'Default PrintNode printer', 'wc-invoice-printer' ) . '</label><select id="wcip-printer-select" name="printnode_printer_id"><option value="' . esc_attr( $s['printnode_printer_id'] ) . '">' . esc_html( $s['printnode_printer_name'] ?: __( 'Refresh to load printers', 'wc-invoice-printer' ) ) . '</option></select><input id="wcip-printer-name" type="hidden" name="printnode_printer_name" value="' . esc_attr( $s['printnode_printer_name'] ) . '"><div class="wcip-actions"><button type="button" class="button button-secondary" data-wcip-action="test-print">' . esc_html__( 'Print test page', 'wc-invoice-printer' ) . '</button></div></section>'; submit_button( __( 'Save printer settings', 'wc-invoice-printer' ) ); echo '</form>';
	}

	private function jobs_screen(): void {
		if ( ! current_user_can( 'wcip_view_print_jobs' ) ) { wp_die( esc_html__( 'You are not allowed to view print jobs.', 'wc-invoice-printer' ), '', array( 'response' => 403 ) ); }
		$page = max( 1, absint( $this->request_value( $_GET, 'paged', '1' ) ) ); $filters = array( 'status' => sanitize_key( $this->request_value( $_GET, 'status' ) ), 'trigger' => sanitize_key( $this->request_value( $_GET, 'trigger' ) ), 'order_id' => absint( $this->request_value( $_GET, 'order_id', '0' ) ) ); $result = $this->jobs->list( $filters, $page );
		echo '<div class="wcip-section-heading"><div><h2>' . esc_html__( 'Print jobs', 'wc-invoice-printer' ) . '</h2><p>' . esc_html__( 'Submitted means PrintNode accepted the PDF or the browser invoice was prepared. It does not confirm paper output.', 'wc-invoice-printer' ) . '</p></div></div><div class="wcip-async-notice" role="status" aria-live="polite"></div><form method="get" class="wcip-filters"><input type="hidden" name="page" value="' . self::SLUG . '"><input type="hidden" name="tab" value="jobs"><label>' . esc_html__( 'Status', 'wc-invoice-printer' ) . ' <select name="status"><option value="">' . esc_html__( 'All', 'wc-invoice-printer' ) . '</option>'; foreach ( JobStatus::cases() as $status ) { echo '<option value="' . esc_attr( $status ) . '" ' . selected( $filters['status'], $status, false ) . '>' . esc_html( ucfirst( $status ) ) . '</option>'; } echo '</select></label><label>' . esc_html__( 'Source', 'wc-invoice-printer' ) . ' <select name="trigger"><option value="">' . esc_html__( 'All', 'wc-invoice-printer' ) . '</option><option value="automatic" ' . selected( $filters['trigger'], 'automatic', false ) . '>' . esc_html__( 'Automatic', 'wc-invoice-printer' ) . '</option><option value="manual" ' . selected( $filters['trigger'], 'manual', false ) . '>' . esc_html__( 'Manual', 'wc-invoice-printer' ) . '</option></select></label><label>' . esc_html__( 'Order ID', 'wc-invoice-printer' ) . ' <input type="search" name="order_id" value="' . esc_attr( $filters['order_id'] ?: '' ) . '"></label><button class="button">' . esc_html__( 'Filter', 'wc-invoice-printer' ) . '</button></form>';
		if ( ! $result['items'] ) { echo '<div class="wcip-empty"><span class="dashicons dashicons-media-document" aria-hidden="true"></span><h3>' . esc_html__( 'No print jobs yet', 'wc-invoice-printer' ) . '</h3><p>' . esc_html__( 'Manual and automatic jobs will appear here with their delivery status.', 'wc-invoice-printer' ) . '</p></div>'; return; }
		echo '<div class="wcip-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Order', 'wc-invoice-printer' ) . '</th><th>' . esc_html__( 'Source', 'wc-invoice-printer' ) . '</th><th>' . esc_html__( 'Template', 'wc-invoice-printer' ) . '</th><th>' . esc_html__( 'Printer', 'wc-invoice-printer' ) . '</th><th>' . esc_html__( 'Status', 'wc-invoice-printer' ) . '</th><th>' . esc_html__( 'Attempts', 'wc-invoice-printer' ) . '</th><th>' . esc_html__( 'Updated', 'wc-invoice-printer' ) . '</th><th>' . esc_html__( 'Details', 'wc-invoice-printer' ) . '</th></tr></thead><tbody>'; foreach ( $result['items'] as $job ) { echo '<tr><td><a href="' . esc_url( $this->order_edit_url( (int) $job['order_id'] ) ) . '">#' . esc_html( $job['order_id'] ) . '</a></td><td>' . esc_html( ucfirst( $job['trigger_type'] ) ) . '</td><td>' . esc_html( $job['template_id'] ) . '</td><td>' . esc_html( $job['printer_id'] ?: __( 'Browser', 'wc-invoice-printer' ) ) . '</td><td><span class="wcip-badge is-' . esc_attr( $job['status'] ) . '">' . esc_html( ucfirst( $job['status'] ) ) . '</span></td><td>' . esc_html( $job['attempt_count'] ) . '</td><td>' . esc_html( get_date_from_gmt( $job['updated_at'] ) ) . '</td><td><details><summary>' . esc_html__( 'View', 'wc-invoice-printer' ) . '</summary><dl><dt>' . esc_html__( 'Provider', 'wc-invoice-printer' ) . '</dt><dd>' . esc_html( $job['provider_id'] ) . '</dd><dt>' . esc_html__( 'External job ID', 'wc-invoice-printer' ) . '</dt><dd>' . esc_html( $job['external_job_id'] ?: '—' ) . '</dd><dt>' . esc_html__( 'Error', 'wc-invoice-printer' ) . '</dt><dd>' . esc_html( $job['error_message'] ?: '—' ) . '</dd></dl>'; if ( current_user_can( 'wcip_print_invoices' ) && 'queued' === $job['status'] ) { echo '<button type="button" class="button-link-delete" data-wcip-job="cancel" data-job-id="' . absint( $job['id'] ) . '">' . esc_html__( 'Cancel queued job', 'wc-invoice-printer' ) . '</button>'; } elseif ( current_user_can( 'wcip_print_invoices' ) && 'failed' === $job['status'] ) { echo '<button type="button" class="button" data-wcip-job="retry" data-job-id="' . absint( $job['id'] ) . '">' . esc_html__( 'Retry if safe', 'wc-invoice-printer' ) . '</button>'; } echo '</details></td></tr>'; } echo '</tbody></table></div>';
		$pages = (int) ceil( $result['total'] / 20 ); if ( $pages > 1 ) { echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'total' => $pages, 'current' => $page ) ) ) . '</div></div>'; }
	}

	private function bulk_screen(): void {
		if ( ! current_user_can( 'wcip_print_invoices' ) ) { wp_die( esc_html__( 'You are not allowed to print invoices.', 'wc-invoice-printer' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'wcip_bulk_print' );
		$selection = sanitize_text_field( $this->request_value( $_GET, 'order_ids' ) );
		$ids = '' !== $selection ? array_unique( explode( ',', $selection ) ) : array();
		if ( count( $ids ) > 50 ) { wp_die( esc_html__( 'Select no more than 50 orders.', 'wc-invoice-printer' ), '', array( 'response' => 400 ) ); }
		foreach ( $ids as $id ) {
			if ( ! preg_match( '/^[1-9][0-9]*$/D', $id ) ) { wp_die( esc_html__( 'Invalid order selection.', 'wc-invoice-printer' ), '', array( 'response' => 400 ) ); }
		}
		if ( ! $ids ) { echo '<div class="wcip-empty"><h3>' . esc_html__( 'No orders selected', 'wc-invoice-printer' ) . '</h3></div>'; return; }
		$preview = wp_nonce_url( add_query_arg( array( 'action' => 'wcip_preview', 'order_ids' => implode( ',', $ids ), 'template' => $this->settings->get( 'default_template', 'classic' ) ), admin_url( 'admin-post.php' ) ), 'wcip_preview_invoices' );
		echo '<section class="wcip-bulk"><h2>' . esc_html( sprintf( _n( 'Print %d invoice', 'Print %d invoices', count( $ids ), 'wc-invoice-printer' ), count( $ids ) ) ) . '</h2><p>' . esc_html__( 'Browser output is combined into one document. PrintNode creates controlled individual background jobs.', 'wc-invoice-printer' ) . '</p><div class="wcip-fields">'; $this->template_select( 'bulk_template', (string) $this->settings->get( 'default_template', 'classic' ) ); echo '<div class="wcip-field"><label class="wcip-label" for="wcip-bulk-output">' . esc_html__( 'Destination', 'wc-invoice-printer' ) . '</label><select id="wcip-bulk-output"><option value="browser">' . esc_html__( 'Browser print dialog', 'wc-invoice-printer' ) . '</option>'; if ( $this->settings->api_key() && $this->settings->get( 'printnode_printer_id' ) ) { echo '<option value="printnode">' . esc_html( sprintf( __( 'PrintNode: %s', 'wc-invoice-printer' ), $this->settings->get( 'printnode_printer_name' ) ) ) . '</option>'; } echo '</select></div>'; $this->field( 'bulk_copies', __( 'Copies', 'wc-invoice-printer' ), 1, 'number', 'min="1" max="20"' ); echo '</div><div class="wcip-async-notice" role="status" aria-live="polite"></div><p><button type="button" class="button button-primary" data-wcip-bulk-print data-order-ids="' . esc_attr( implode( ',', $ids ) ) . '" data-preview-url="' . esc_url( $preview ) . '">' . esc_html__( 'Start bulk printing', 'wc-invoice-printer' ) . '</button></p></section>';
	}

	private function request_value( array $input, string $key, string $default = '' ): string {
		return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) wp_unslash( (string) $input[ $key ] ) : $default;
	}

	private function order_edit_url( int $order_id ): string {
		$order = wc_get_order( $order_id );
		return $order instanceof \WC_Order ? $order->get_edit_order_url() : '';
	}

	private function field( string $name, string $label, $value, string $type, string $attributes = '' ): void { echo '<div class="wcip-field"><label for="wcip-' . esc_attr( $name ) . '" class="wcip-label">' . esc_html( $label ) . '</label><input id="wcip-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( (string) $value ) . '" ' . $attributes . '></div>'; }
	private function textarea( string $name, string $label, $value ): void { echo '<div class="wcip-field wcip-field-wide"><label for="wcip-' . esc_attr( $name ) . '" class="wcip-label">' . esc_html( $label ) . '</label><textarea id="wcip-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" rows="4">' . esc_textarea( (string) $value ) . '</textarea></div>'; }
	private function template_select( string $name, string $selected ): void { echo '<div class="wcip-field"><label for="wcip-' . esc_attr( $name ) . '" class="wcip-label">' . esc_html__( 'Invoice template', 'wc-invoice-printer' ) . '</label><select id="wcip-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">'; foreach ( $this->templates->all() as $template ) { echo '<option value="' . esc_attr( $template->id ) . '" ' . selected( $selected, $template->id, false ) . '>' . esc_html( $template->name . ' · ' . $template->paper_size ) . '</option>'; } echo '</select></div>'; }
}
