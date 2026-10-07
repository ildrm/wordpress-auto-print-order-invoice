<?php

require dirname( __DIR__ ) . '/vendor/autoload.php';

defined( 'WCIP_PATH' ) || define( 'WCIP_PATH', dirname( __DIR__ ) . '/' );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'MB_IN_BYTES' ) || define( 'MB_IN_BYTES', 1048576 );
defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__ ) . '/' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['wcip_test_options']   = array();
$GLOBALS['wcip_test_transients'] = array();
$GLOBALS['wcip_http_response']  = array( 'response' => array( 'code' => 200 ), 'body' => '{}' );

if ( ! function_exists( '__' ) ) { function __( string $text, string $domain = '' ): string { return 'wc-invoice-printer' === $domain ? ( $GLOBALS['wcip_test_translations'][ $text ] ?? $text ) : $text; } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( string $show = '' ): string { return 'Test Store'; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( string $hook, $value, ...$args ) { foreach ( $GLOBALS['wcip_test_filters'][ $hook ] ?? array() as $filter ) { $value = $filter( $value, ...$args ); } return $value; } }
if ( ! function_exists( 'do_action' ) ) { function do_action( string $hook, ...$args ): void { $GLOBALS['wcip_test_actions'][] = array( $hook, $args ); foreach ( $GLOBALS['wcip_test_hooks'][ $hook ] ?? array() as $callback ) { $callback( ...$args ); } } }
if ( ! function_exists( 'get_locale' ) ) { function get_locale(): string { return $GLOBALS['wcip_test_locale'] ?? 'en_US'; } }
if ( ! function_exists( 'determine_locale' ) ) { function determine_locale(): string { return $GLOBALS['wcip_test_user_locale'] ?? get_locale(); } }
if ( ! function_exists( 'switch_to_locale' ) ) {
	function switch_to_locale( string $locale ): bool {
		if ( determine_locale() === $locale ) { return false; }
		$state = array();
		foreach ( array( 'wcip_test_locale', 'wcip_test_user_locale', 'wcip_test_translations' ) as $key ) {
			if ( array_key_exists( $key, $GLOBALS ) ) { $state[ $key ] = $GLOBALS[ $key ]; }
		}
		$GLOBALS['wcip_test_locale_stack'][] = $state;
		$GLOBALS['wcip_test_locale'] = $locale;
		unset( $GLOBALS['wcip_test_user_locale'] );
		$GLOBALS['wcip_test_translations'] = is_file( WCIP_PATH . 'languages/wc-invoice-printer-' . $locale . '.mo' ) ? \WCInvoicePrinter\Tests\Support\TranslationCatalog::load( $locale ) : array();
		return true;
	}
}
if ( ! function_exists( 'restore_previous_locale' ) ) {
	function restore_previous_locale() {
		if ( empty( $GLOBALS['wcip_test_locale_stack'] ) ) { return false; }
		$state = array_pop( $GLOBALS['wcip_test_locale_stack'] );
		foreach ( array( 'wcip_test_locale', 'wcip_test_user_locale', 'wcip_test_translations' ) as $key ) {
			unset( $GLOBALS[ $key ] );
			if ( array_key_exists( $key, $state ) ) { $GLOBALS[ $key ] = $state[ $key ]; }
		}
		return determine_locale();
	}
}
if ( ! function_exists( 'is_rtl' ) ) { function is_rtl(): bool { return in_array( substr( determine_locale(), 0, 2 ), array( 'fa', 'ar' ), true ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); } }
if ( ! function_exists( 'esc_html_e' ) ) { function esc_html_e( string $value, string $domain = '' ): void { echo esc_html( __( $value, $domain ) ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( string $value ): string { return strip_tags( $value, '<strong><span><small><bdi><del><ins>' ); } }
if ( ! function_exists( 'get_option' ) ) { function get_option( string $key, $default = false ) { return $GLOBALS['wcip_test_options'][ $key ] ?? $default; } }
if ( ! function_exists( 'wp_parse_args' ) ) { function wp_parse_args( array $args, array $defaults ): array { return array_merge( $defaults, $args ); } }
if ( ! function_exists( 'wp_generate_uuid4' ) ) { function wp_generate_uuid4(): string { return sprintf( '%08x-%04x-4%03x-8%03x-%012x', random_int( 0, 0xffffffff ), random_int( 0, 0xffff ), random_int( 0, 0xfff ), random_int( 0, 0xfff ), random_int( 0, 0xffffffffffff ) ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); } }
if ( ! function_exists( 'absint' ) ) { function absint( $value ): int { return abs( (int) $value ); } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( string $key ) { return $GLOBALS['wcip_test_transients'][ $key ] ?? false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( string $key, $value ): bool { $GLOBALS['wcip_test_transients'][ $key ] = $value; return true; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) { return json_encode( $value, $flags, $depth ); } }
if ( ! function_exists( 'wp_safe_remote_request' ) ) { function wp_safe_remote_request( string $url, array $args ) { $GLOBALS['wcip_last_http'] = array( $url, $args ); if ( isset( $GLOBALS['wcip_http_callback'] ) ) { return ( $GLOBALS['wcip_http_callback'] )( $url, $args ); } return $GLOBALS['wcip_http_response']; } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $value ): bool { return $value instanceof WP_Error; } }
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( array $response ): int { return (int) ( $response['response']['code'] ?? 0 ); } }
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) { function wp_remote_retrieve_body( array $response ): string { return (string) ( $response['body'] ?? '' ); } }
if ( ! function_exists( 'get_temp_dir' ) ) { function get_temp_dir(): string { return $GLOBALS['wcip_temp_dir'] ?? sys_get_temp_dir() . '/'; } }
if ( ! function_exists( 'trailingslashit' ) ) { function trailingslashit( string $value ): string { return rtrim( $value, '/\\' ) . '/'; } }
if ( ! function_exists( 'wp_mkdir_p' ) ) { function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0777, true ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( string $type, bool $gmt = false ): string { return '2026-09-15 09:00:00'; } }
if ( ! function_exists( 'as_enqueue_async_action' ) ) { function as_enqueue_async_action( string $hook, array $args, string $group, bool $unique ): int { $GLOBALS['wcip_scheduled_action'] = compact( 'hook', 'args', 'group', 'unique' ); if ( isset( $GLOBALS['wcip_async_action_exception'] ) ) { throw $GLOBALS['wcip_async_action_exception']; } if ( isset( $GLOBALS['wcip_async_action_callback'] ) ) { return ( $GLOBALS['wcip_async_action_callback'] )( $hook, $args, $group, $unique ); } return $GLOBALS['wcip_async_action_result'] ?? 101; } }
if ( ! function_exists( 'as_schedule_single_action' ) ) { function as_schedule_single_action( int $timestamp, string $hook, array $args, string $group, bool $unique ): int { $GLOBALS['wcip_scheduled_action'] = compact( 'timestamp', 'hook', 'args', 'group', 'unique' ); if ( isset( $GLOBALS['wcip_single_action_exception'] ) ) { throw $GLOBALS['wcip_single_action_exception']; } return $GLOBALS['wcip_single_action_result'] ?? 102; } }

require __DIR__ . '/Support/WordPress.php';
