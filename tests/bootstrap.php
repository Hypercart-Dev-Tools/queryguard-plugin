<?php
/**
 * PHPUnit bootstrap for Hypercart Query Guard.
 *
 * Provides the minimal set of WordPress function stubs required to load
 * hypercart-query-guard.php in a plain PHP environment without a WordPress
 * installation. Only functions actually called during plugin load and during
 * the test scenarios below are stubbed here; this keeps the surface small
 * and obvious.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// Run in observe mode so init() does not call apply_session_timeout()
// (which needs a real $wpdb->dbh connection object).
if ( ! defined( 'HYPERCART_QUERY_GUARD_MODE' ) ) {
	define( 'HYPERCART_QUERY_GUARD_MODE', 'observe' );
}

// SAVEQUERIES must be true so log_slow_queries() processes the mock query list.
if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true );
}

// ---------------------------------------------------------------------------
// Lightweight filter/action registry.
// apply_filters and add_filter behave like WordPress so test code can use
// the hypercart_query_guard_log_payload hook to capture payloads.
// ---------------------------------------------------------------------------

$GLOBALS['_qg_test_filters'] = array();

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['_qg_test_filters'][ $hook ][] = $callback;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	add_filter( $hook, $callback, $priority, $accepted_args );
}

function apply_filters( $hook, $value ) {
	$extra = array_slice( func_get_args(), 2 );
	if ( ! empty( $GLOBALS['_qg_test_filters'][ $hook ] ) ) {
		foreach ( $GLOBALS['_qg_test_filters'][ $hook ] as $cb ) {
			$value = $cb( $value, ...$extra );
		}
	}
	return $value;
}

function remove_all_filters( $hook ) {
	unset( $GLOBALS['_qg_test_filters'][ $hook ] );
}

// ---------------------------------------------------------------------------
// WordPress function stubs
// ---------------------------------------------------------------------------

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook ) {
		return 0;
	}
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax() {
		return defined( 'DOING_AJAX' ) && DOING_AJAX;
	}
}

if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron() {
		return defined( 'DOING_CRON' ) && DOING_CRON;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return defined( 'WP_ADMIN' ) && WP_ADMIN;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 0;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return $str;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $str ) {
		return $str;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return false;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		return true;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return $text;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

// Load the plugin (registers the class; calls init() which adds WordPress
// hooks via add_action / add_filter — both stubbed above).
require_once dirname( __DIR__ ) . '/hypercart-query-guard.php';
