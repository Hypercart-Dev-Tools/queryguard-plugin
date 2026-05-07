<?php
/**
 * PHPUnit bootstrap. The classes under test reach for a small set of WordPress
 * functions; we stub them in-process so the suite has no WP dependency.
 *
 * The stubs are stateful — reset between tests via WP_Stub_State::reset().
 *
 * @package Hypercart
 */

define( 'ABSPATH', __DIR__ . '/' );

// Force the plugin's bootstrap to early-return so requiring the main file
// in tests does not register hooks or attempt SET SESSION.
if ( ! defined( 'HYPERCART_QUERY_GUARD_MODE' ) ) {
	define( 'HYPERCART_QUERY_GUARD_MODE', 'off' );
}
if ( ! defined( 'HYPERCART_QUERY_GUARD_THROTTLE_MODE' ) ) {
	define( 'HYPERCART_QUERY_GUARD_THROTTLE_MODE', 'off' );
}

final class WP_Stub_State {
	/** @var array<string,array<int,callable>> */
	public static $filters = array();
	/** @var array<string,mixed> */
	public static $options = array();
	/** @var array<string,mixed> */
	public static $cache = array();
	/** @var bool */
	public static $ext_object_cache = false;

	public static function reset(): void {
		self::$filters          = array();
		self::$options          = array();
		self::$cache            = array();
		self::$ext_object_cache = false;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		$args = func_get_args();
		array_shift( $args ); // tag
		if ( empty( WP_Stub_State::$filters[ $tag ] ) ) {
			return $value;
		}
		foreach ( WP_Stub_State::$filters[ $tag ] as $callback ) {
			$args[0] = call_user_func_array( $callback, $args );
		}
		return $args[0];
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, callable $callback ) {
		WP_Stub_State::$filters[ $tag ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, WP_Stub_State::$options ) ? WP_Stub_State::$options[ $name ] : $default;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $name, $value ) {
		if ( array_key_exists( $name, WP_Stub_State::$options ) ) {
			return false;
		}
		WP_Stub_State::$options[ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value ) {
		WP_Stub_State::$options[ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '' ) {
		$composite = $group . ':' . $key;
		return array_key_exists( $composite, WP_Stub_State::$cache ) ? WP_Stub_State::$cache[ $composite ] : false;
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $value, $group = '' ) {
		$composite                          = $group . ':' . $key;
		WP_Stub_State::$cache[ $composite ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
	function wp_using_ext_object_cache() {
		return WP_Stub_State::$ext_object_cache;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, callable $callback ) {
		WP_Stub_State::$filters[ $tag ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $tag ) {
		return 0;
	}
}

if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron() {
		return false;
	}
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax() {
		return false;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}

require_once __DIR__ . '/../class-hcqg-load-monitor.php';
require_once __DIR__ . '/../class-hcqg-priority-registry.php';
require_once __DIR__ . '/../hypercart-query-guard.php';
