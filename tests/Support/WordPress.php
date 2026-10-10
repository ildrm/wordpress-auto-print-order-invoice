<?php

// Deliberately small, controllable WordPress doubles. These are not integration tests.
class WP_Error {
	public string $code;
	public string $message;
	public $data;

	public function __construct( string $code = '', string $message = '', $data = null ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data() { return $this->data; }
}

class WP_REST_Request implements ArrayAccess {
	private array $params = array();
	public string $method;
	public string $route;

	public function __construct( string $method = 'GET', string $route = '' ) {
		$this->method = $method;
		$this->route = $route;
	}
	public function set_param( string $key, $value ): void { $this->params[ $key ] = $value; }
	public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
	public function set_body_params( array $params ): void { $this->params = array_merge( $this->params, $params ); }
	public function set_body( string $body ): void { $this->set_body_params( json_decode( $body, true ) ?: array() ); }
	public function set_header( string $key, string $value ): void {}
	public function get_json_params(): array { return $this->params; }
	public function offsetExists( $offset ): bool { return isset( $this->params[ $offset ] ); }
	// PHP 7.4 treats this attribute as a comment; PHP 8.1+ uses it for ArrayAccess.
	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) { return $this->params[ $offset ] ?? null; }
	public function offsetSet( $offset, $value ): void { $this->params[ $offset ] = $value; }
	public function offsetUnset( $offset ): void { unset( $this->params[ $offset ] ); }
}

class WP_REST_Response {
	private $data;
	private int $status;

	public function __construct( $data = null, int $status = 200 ) {
		$this->data = $data;
		$this->status = $status;
	}
	public function get_data() { return $this->data; }
	public function get_status(): int { return $this->status; }
	public function header( string $key, string $value ): void {}
}

function is_ssl(): bool { return (bool) ( $GLOBALS['wcip_test_ssl'] ?? false ); }
function rest_get_authenticated_app_password() { return $GLOBALS['wcip_test_app_password'] ?? null; }

class ActionScheduler {
	public static bool $initialized = true;
	public static function is_initialized(): bool { return self::$initialized; }
}

class WC_Order {
	private int $id;
	private string $status;
	private string $transaction_id;

	public function __construct( int $id = 1, string $status = 'processing', string $transaction_id = '' ) {
		$this->id = $id;
		$this->status = $status;
		$this->transaction_id = $transaction_id;
	}
	public function get_id(): int { return $this->id; }
	public function get_type(): string { return 'shop_order'; }
	public function get_status(): string { return $this->status; }
	public function set_status( string $status ): void { $this->status = $status; }
	public function has_status( $statuses ): bool { return in_array( $this->status, (array) $statuses, true ); }
	public function is_paid(): bool { return in_array( $this->status, wc_get_is_paid_statuses(), true ); }
	public function get_transaction_id(): string { return $this->transaction_id; }
	public function set_transaction_id( string $id ): void { $this->transaction_id = $id; }
	public function get_order_number(): string { return (string) $this->id; }
	public function get_edit_order_url(): string { return 'https://example.test/wp-admin/post.php?post=' . $this->id . '&action=edit'; }
	public function get_items(): array { return array(); }
	public function get_order_item_totals(): array { return array(); }
	public function get_item_subtotal( $item, bool $inc_tax = false, bool $round = true ): float { return 0.0; }
	public function get_formatted_line_subtotal( $item ): string { return ''; }
	public function get_date_created() { return null; }
	public function get_date_paid() { return $this->is_paid() ? '2026-10-10' : null; }
	public function get_currency(): string { return 'USD'; }
	public function get_formatted_billing_full_name(): string { return 'Test Customer'; }
	public function get_billing_company(): string { return ''; }
	public function get_formatted_billing_address(): string { return ''; }
	public function get_formatted_shipping_address(): string { return ''; }
	public function get_billing_phone(): string { return ''; }
	public function get_billing_email(): string { return ''; }
	public function get_payment_method_title(): string { return 'Card'; }
	public function get_shipping_method(): string { return ''; }
	public function get_customer_note(): string { return ''; }
	public function add_order_note( string $note, $customer = false, bool $by_user = false ): int {
		if ( $GLOBALS['wcip_note_failure'] ?? false ) { return 0; }
		$id = count( $GLOBALS['wcip_test_notes'] ?? array() ) + 1;
		$GLOBALS['wcip_test_notes'][ $id ] = array( 'order_id' => $this->id, 'note' => $note, 'customer' => $customer, 'by_user' => $by_user );
		return $id;
	}
}

class WC_Order_Item_Product {}

function wc_get_order( $id ) { return isset( $GLOBALS['wcip_order_lookup'] ) ? ( $GLOBALS['wcip_order_lookup'] )( (int) $id ) : ( $GLOBALS['wcip_test_orders'][ (int) $id ] ?? false ); }
function wc_get_orders( array $args ): array { return isset( $GLOBALS['wcip_order_query'] ) ? ( $GLOBALS['wcip_order_query'] )( $args ) : array(); }
function get_current_user_id(): int { return $GLOBALS['wcip_test_user_id'] ?? 42; }
function wp_delete_comment( int $id, bool $force = false ): bool { unset( $GLOBALS['wcip_test_notes'][ $id ] ); return true; }
function clean_comment_cache( int $id ): void {}
function wc_get_is_paid_statuses(): array { return array( 'processing', 'completed' ); }
function current_user_can( string $capability ): bool { return (bool) ( $GLOBALS['wcip_test_capabilities'][ $capability ] ?? false ); }
function update_option( string $name, $value, $autoload = null ): bool { $GLOBALS['wcip_test_options'][ $name ] = $value; return true; }
function delete_option( string $name ): bool { unset( $GLOBALS['wcip_test_options'][ $name ] ); return true; }
function delete_transient( string $name ): bool { unset( $GLOBALS['wcip_test_transients'][ $name ] ); return true; }
function register_rest_route( string $namespace, string $route, array $args ): void { $GLOBALS['wcip_test_routes'][ $namespace . $route ] = $args; }
function esc_html__( string $value, string $domain = '' ): string { return esc_html( __( $value, $domain ) ); }
function esc_attr__( string $value, string $domain = '' ): string { return esc_attr( __( $value, $domain ) ); }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function sanitize_email( $value ): string { return filter_var( (string) $value, FILTER_SANITIZE_EMAIL ); }
function sanitize_textarea_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
function esc_url_raw( $value ): string { return (string) $value; }
function wp_strip_all_tags( $value ): string { return strip_tags( (string) $value ); }
function wp_kses( string $value, array $allowed ): string { return strip_tags( $value, implode( '', array_map( static fn( $tag ) => '<' . $tag . '>', array_keys( $allowed ) ) ) ); }
function wc_price( $value, array $args = array() ): string { return ( $args['currency'] ?? 'USD' ) . ' ' . number_format( (float) $value, 2, '.', '' ); }
function wc_format_datetime( $date ): string { return (string) $date; }
function wc_get_order_status_name( string $status ): string { return ucfirst( $status ); }
function wp_safe_remote_get( string $url, array $args = array() ) { return wp_safe_remote_request( $url, $args ); }
function wp_remote_retrieve_header( array $response, string $name ): string { return (string) ( $response['headers'][ $name ] ?? '' ); }
function wp_http_validate_url( string $url ) { return filter_var( $url, FILTER_VALIDATE_URL ); }
function check_admin_referer( string $action ): bool { if ( ! ( $GLOBALS['wcip_test_nonce_valid'] ?? true ) ) { throw new RuntimeException( 'Invalid nonce' ); } return true; }
function wp_die( string $message, string $title = '', array $args = array() ) { throw new RuntimeException( $message, (int) ( $args['response'] ?? 500 ) ); }
function as_has_scheduled_action( string $hook, array $args = array(), string $group = '' ): bool { return (bool) ( $GLOBALS['wcip_existing_action_id'] ?? false ); }
function as_next_scheduled_action( string $hook, array $args = array(), string $group = '' ) { return isset( $GLOBALS['wcip_existing_action_id'] ) ? true : false; }
function as_get_scheduled_actions( array $args, string $format = 'OBJECT' ): array { return isset( $GLOBALS['wcip_existing_action_id'] ) && ( $args['status'] ?? 'pending' ) === ( $GLOBALS['wcip_existing_action_status'] ?? 'pending' ) ? array( $GLOBALS['wcip_existing_action_id'] ) : array(); }
function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): void { $GLOBALS['wcip_unscheduled_actions'][] = compact( 'hook', 'args', 'group' ); }
function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void { $GLOBALS['wcip_registered_hooks'][ $hook ][] = compact( 'callback', 'priority', 'accepted_args' ); }
function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void { add_action( $hook, $callback, $priority, $accepted_args ); }
function get_role( string $name ) { return $GLOBALS['wcip_test_roles'][ $name ] ?? null; }
function nocache_headers(): void { $GLOBALS['wcip_nocache_called'] = true; if ( isset( $GLOBALS['wcip_nocache_exception'] ) ) { throw $GLOBALS['wcip_nocache_exception']; } }
