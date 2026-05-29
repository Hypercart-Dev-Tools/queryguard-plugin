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

/**
 * Minimal $wpdb stub. Real wpdb is intricate; we only model the surface
 * the SUTs touch: ->options, ->prepare(), ->query(), ->get_var(),
 * ->rows_affected. Tests configure return values via the next_* queues
 * and inspect issued SQL via $queries.
 */
final class WP_Stub_DB {
	/** @var string */
	public $options = 'wp_options';
	/** @var int */
	public $rows_affected = 0;

	/** @var array<int,string> Rendered SQL captured from query() / get_var(). */
	public $queries = array();
	/** @var array<int,array{query:string,args:array}> Raw args from prepare(). */
	public $prepared = array();

	/** @var array<int,int> FIFO queue of rows_affected returns for query(). */
	public $next_query_rows_affected = array();
	/** @var array<int,?string> FIFO queue of get_var() returns. */
	public $next_get_var = array();

	// v2 drop-in simulation fields.
	/** @var array Simulates HCQG_DB::$hcqg_slow_queries. */
	public $hcqg_slow_queries = array();
	/** @var bool Whether to simulate an active drop-in. */
	private $hcqg_active = false;
	/** @var int|null Last limit passed to hcqg_update_limit(). */
	public $hcqg_last_limit = null;
	/** @var mixed Simulated connection handle. */
	public $dbh = null;
	/** @var string */
	public $last_error = '';
	/** @var int */
	public $num_queries = 0;

	public function hcqg_is_active(): bool { return $this->hcqg_active; }
	public function hcqg_set_active( bool $active ): void { $this->hcqg_active = $active; }
	public function hcqg_update_limit( int $limit_ms ): void { $this->hcqg_last_limit = $limit_ms; }

	/** @var bool */
	private $suppress = false;

	public function suppress_errors( $suppress = true ) {
		$prev = $this->suppress;
		$this->suppress = (bool) $suppress;
		return $prev;
	}

	public function reset(): void {
		$this->rows_affected            = 0;
		$this->queries                  = array();
		$this->prepared                 = array();
		$this->next_query_rows_affected = array();
		$this->next_get_var             = array();
		$this->hcqg_slow_queries        = array();
		$this->hcqg_active              = false;
		$this->hcqg_last_limit          = null;
		$this->dbh                      = null;
		$this->last_error               = '';
		$this->num_queries              = 0;
		$this->suppress                 = false;
	}

	/**
	 * Substitute %s/%d/%f placeholders left-to-right with the supplied
	 * args. This is a test-grade renderer, not a security-grade one —
	 * we only need it to make the resulting SQL inspectable in assertions.
	 */
	public function prepare( $query, ...$args ) {
		$this->prepared[] = array( 'query' => $query, 'args' => $args );

		$rendered = $query;
		foreach ( $args as $arg ) {
			if ( ! preg_match( '/%[sdf]/', $rendered, $m, PREG_OFFSET_CAPTURE ) ) {
				break;
			}
			$token = $m[0][0];
			$pos   = $m[0][1];
			if ( '%s' === $token ) {
				$sub = "'" . str_replace( "'", "\\'", (string) $arg ) . "'";
			} elseif ( '%d' === $token ) {
				$sub = (string) (int) $arg;
			} else {
				$sub = (string) (float) $arg;
			}
			$rendered = substr_replace( $rendered, $sub, $pos, strlen( $token ) );
		}
		return $rendered;
	}

	public function query( $sql ) {
		$this->queries[]     = $sql;
		$this->rows_affected = empty( $this->next_query_rows_affected ) ? 0 : (int) array_shift( $this->next_query_rows_affected );
		return $this->rows_affected;
	}

	public function get_var( $sql ) {
		$this->queries[] = $sql;
		if ( empty( $this->next_get_var ) ) {
			return null;
		}
		return array_shift( $this->next_get_var );
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

if ( ! function_exists( 'wp_cache_add' ) ) {
	function wp_cache_add( $key, $value, $group = '', $expire = 0 ) {
		$composite = $group . ':' . $key;
		if ( array_key_exists( $composite, WP_Stub_State::$cache ) ) {
			return false;
		}
		WP_Stub_State::$cache[ $composite ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_cache_incr' ) ) {
	function wp_cache_incr( $key, $offset = 1, $group = '' ) {
		$composite = $group . ':' . $key;
		if ( ! array_key_exists( $composite, WP_Stub_State::$cache ) ) {
			return false;
		}
		WP_Stub_State::$cache[ $composite ] = (int) WP_Stub_State::$cache[ $composite ] + $offset;
		return WP_Stub_State::$cache[ $composite ];
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

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 0;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return is_string( $str ) ? trim( strip_tags( $str ) ) : '';
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return esc_html( $text );
	}
}

/**
 * Stub logger that captures calls so tests can inspect log output
 * without relying on error_log() interception.
 */
class Hypercart_Logger {
	/** @var array<int,array{level:string,channel:string,payload:array}> */
	public static $calls = array();

	public static function reset(): void { self::$calls = array(); }
	public static function error( $channel, $payload ) { self::$calls[] = array( 'level' => 'error', 'channel' => $channel, 'payload' => $payload ); }
	public static function info( $channel, $payload ) { self::$calls[] = array( 'level' => 'info', 'channel' => $channel, 'payload' => $payload ); }
	public static function warning( $channel, $payload ) { self::$calls[] = array( 'level' => 'warning', 'channel' => $channel, 'payload' => $payload ); }
}

$GLOBALS['wpdb'] = new WP_Stub_DB();

require_once __DIR__ . '/../class-hcqg-load-monitor.php';
require_once __DIR__ . '/../class-hcqg-priority-registry.php';
require_once __DIR__ . '/../class-hcqg-mutex-guard.php';
require_once __DIR__ . '/../hypercart-query-guard.php';
