<?php
/**
 * Plugin Name:       Hypercart Query Guard
 * Plugin URI:        https://hypercart.io
 * Description:       PHP-side circuit breaker that enforces MySQL MAX_EXECUTION_TIME on read queries to prevent runaway SELECTs from saturating the pod. Tiered limits per request context, observe-mode for safe rollout, automatic re-application on connection rotation, and admin-search timeout fallback.
 * Version:           1.3.0
 * Author:            Hypercart / Neochrome
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP:      7.4
 *
 * Drop in:           Copy this file plus class-hcqg-*.php into
 *                    wp-content/mu-plugins/
 *
 * Mode control:      define( 'HYPERCART_QUERY_GUARD_MODE', 'observe' );
 *                    Modes: 'off' | 'observe' | 'enforce' (default: 'observe')
 *
 * AS throttle:       define( 'HYPERCART_QUERY_GUARD_THROTTLE_MODE', 'test_observe' );
 *                    Modes: 'off' | 'test_observe' | 'observe' | 'enforce'
 *                    (default: 'off')
 *
 * Order-notes probe: define( 'HYPERCART_QUERY_GUARD_LOG_ORDER_NOTES', true );
 *                    Diagnostic. Logs a backtrace for unscoped
 *                    wc_get_order_notes() loads (the order-notes mega-query)
 *                    to identify the calling code. Off by default.
 *
 * v2 drop-in:        Optional wp-content/db.php applies SET SESSION at
 *                    connection time and conditionally backtraces slow
 *                    queries. Without the drop-in, this MU-plugin falls
 *                    back to the v1 init-priority-1 behavior.
 *
 * Cart diagnostic:   define( 'HYPERCART_CART_TYPE_DIAGNOSTIC', true );
 *                    Traces non-numeric cart item values (quantity, price,
 *                    discounted_price) that cause the PHP 8 TypeError in
 *                    WC_Discounts::sort_by_price(). Emits two error events:
 *                    'cart_type_corruption' (hook-window detection with
 *                    origin attribution and callback lists) and
 *                    'cart_fatal_captured' (shutdown-time capture of the
 *                    fatal itself, on any code path). Override via the
 *                    'hypercart_cart_type_diagnostic_enabled' filter from
 *                    wp-config.php or an earlier-loading mu-plugin. All
 *                    entry points swallow Throwables — the tracer can
 *                    never take down the cart it observes. Log volume is
 *                    capped per process with per-signature de-duplication.
 *                    Enable to reproduce, then disable: while enabled it
 *                    adds one filtered get_price() read per cart item per
 *                    totals calculation (the scan runs even on clean
 *                    carts), so it is not intended to stay on permanently.
 *
 * @package Hypercart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$hcqg_required_files = array(
	__DIR__ . '/class-hcqg-load-monitor.php',
	__DIR__ . '/class-hcqg-priority-registry.php',
	__DIR__ . '/class-hcqg-mutex-guard.php',
);
foreach ( $hcqg_required_files as $hcqg_file ) {
	if ( ! file_exists( $hcqg_file ) ) {
		error_log( '[hypercart_query_guard][error] Missing required file: ' . basename( $hcqg_file ) . ' - plugin disabled.' );
		return;
	}
}
unset( $hcqg_required_files, $hcqg_file );

require_once __DIR__ . '/class-hcqg-load-monitor.php';
require_once __DIR__ . '/class-hcqg-priority-registry.php';
require_once __DIR__ . '/class-hcqg-mutex-guard.php';

if ( ! class_exists( 'Hypercart_Query_Guard' ) ) {

	final class Hypercart_Query_Guard {

		/**
		 * Operating modes.
		 */
		const MODE_OFF     = 'off';
		const MODE_OBSERVE = 'observe';
		const MODE_ENFORCE = 'enforce';

		/**
		 * Action Scheduler throttle modes.
		 */
		const THROTTLE_MODE_OFF          = 'off';
		const THROTTLE_MODE_TEST_OBSERVE = 'test_observe';
		const THROTTLE_MODE_OBSERVE      = 'observe';
		const THROTTLE_MODE_ENFORCE      = 'enforce';

		/**
		 * Load levels for Action Scheduler throttling.
		 */
		const THROTTLE_LEVEL_NORMAL   = 'normal';
		const THROTTLE_LEVEL_ELEVATED = 'elevated';
		const THROTTLE_LEVEL_CRITICAL = 'critical';

		/**
		 * Max unscoped order-note diagnostic log lines per request. Volume
		 * guard for the optional order-notes caller probe — the unscoped
		 * load normally fires once per request, but a looping caller is
		 * capped here so it can never flood the log.
		 */
		const ORDER_NOTES_LOG_MAX_PER_REQUEST = 5;

		/**
		 * Count of order-note diagnostic lines emitted this request.
		 *
		 * @var int
		 */
		private static $order_notes_log_count = 0;

		/**
		 * Default throttle policy by load level.
		 */
		const THROTTLE_POLICY = array(
			'elevated' => array(
				'batch_size'         => 5,
				'time_limit'         => 15,
				'concurrent_batches' => 1,
			),
			'critical' => array(
				'batch_size'         => 1,
				'time_limit'         => 10,
				'concurrent_batches' => 1,
			),
		);

		/**
		 * Default per-action deferral delays by load level and priority tier.
		 *
		 * Values are in seconds. 0 means "run immediately".
		 */
		const THROTTLE_ACTION_DELAYS = array(
			'elevated' => array(
				'critical'   => 0,
				'high'       => 0,
				'normal'     => 300,
				'deferrable' => 900,
			),
			'critical' => array(
				'critical'   => 0,
				'high'       => 300,
				'normal'     => 900,
				'deferrable' => 3600,
			),
		);

		/**
		 * Warning threshold for observe-mode logging (ms).
		 * Queries slower than this get logged but not killed.
		 */
		const WARN_THRESHOLD_MS = 5000;

		/**
		 * Sampling rate for observe mode (percentage of requests).
		 * SAVEQUERIES has real memory overhead; don't run it on 100% of traffic.
		 */
		const OBSERVE_SAMPLE_PCT = 5;

		/**
		 * Tiered execution-time ceilings per request context (ms).
		 * 0 = unlimited (used for WP-CLI and Action Scheduler workers).
		 *
		 * Order matters: detection runs top-to-bottom, first match wins.
		 */
		const LIMITS_MS = array(
			'wp_cli'           => 0,
			'action_scheduler' => 0,
			'wp_cron'          => 10000,
			// Tightened from 20 s → 10 s (2026-05-30).
			// admin-ajax.php is the dominant vector for runaway read queries (Facebook
			// background sync, WC order-note loading via oversized IN lists, NoFraud).
			// Halving the ceiling halves the per-execution DB exposure when concurrent
			// workers pile up. Legitimate heavyweight reads that truly need > 10 s should
			// use the REST API (30 s ceiling) or be routed through WP-Admin (45 s ceiling).
			// Operators can relax for specific actions via the hypercart_query_guard_limit_ms
			// filter. The checkout context (60 s) is unchanged — it is detected before
			// admin_ajax and covers both the AJAX and block REST checkout endpoints.
			'admin_ajax'       => 10000,
			'rest_api'         => 30000,
			'checkout'         => 60000,
			'wp_admin'         => 45000,
			'frontend'         => 30000,
		);

		/**
		 * MySQL error code for query timeout (ER_QUERY_TIMEOUT).
		 */
		const MYSQL_ERR_QUERY_TIMEOUT = 3024;

		/**
		 * Transient prefix for admin-search timeout fallback notices.
		 */
		const ADMIN_NOTICE_TRANSIENT = 'hcqg_admin_killed_';

		/**
		 * High-water mark for the last query number we've already inspected
		 * for a kill. wpdb::$num_queries is monotonic, so comparing against
		 * it lets us distinguish "fresh error from a new query" from "stale
		 * error we already logged" — even when two consecutive kills produce
		 * the same error string, which the NoFraud thundering-herd pattern
		 * does in practice.
		 *
		 * @var int
		 */
		private static $last_checked_query_num = -1;

		/**
		 * Per-request throttle memoization and one-shot logging.
		 *
		 * @var array<string,mixed>
		 */
		private static $throttle_runtime = array();

		/**
		 * Cart type diagnostic: maximum corruption events logged per PHP
		 * process. Bounded so a corrupted cart cannot flood the log, yet
		 * high enough that a long-running process (an Action Scheduler
		 * batch touching many carts) can report several distinct findings.
		 */
		const CART_DIAG_MAX_LOGS = 5;

		/**
		 * Cart type diagnostic: early snapshot of cart item values,
		 * keyed by cart item key.
		 *
		 * @var array<string, array>
		 */
		private static $cart_diag_snapshot = array();

		/**
		 * Cart type diagnostic: corruption events logged by this process.
		 *
		 * @var int
		 */
		private static $cart_diag_log_count = 0;

		/**
		 * Cart type diagnostic: signatures of corruption sets already
		 * logged, so repeat hook firings do not re-log identical findings.
		 *
		 * @var array<string, bool>
		 */
		private static $cart_diag_logged_sigs = array();

		/**
		 * Cart type diagnostic: true while a snapshot/check pair is in
		 * flight; guards re-entrant calculate_totals() calls from
		 * overwriting the outer snapshot mid-hook.
		 *
		 * @var bool
		 */
		private static $cart_diag_in_progress = false;

		/**
		 * Whether the v2 db.php drop-in is active for this request.
		 *
		 * @return bool
		 */
		private static function dropin_active() {
			global $wpdb;
			return ( isset( $wpdb ) && method_exists( $wpdb, 'hcqg_is_active' ) && $wpdb->hcqg_is_active() );
		}

		/**
		 * Bootstrap.
		 */
		public static function init() {
			// Cart type diagnostic — independent of query guard mode.
			if ( self::cart_diag_enabled() ) {
				// PHP_INT_MIN/PHP_INT_MAX bracket the widest window a WP hook
				// allows: priority-0/negative callbacks still run after the
				// snapshot, and "run last" callbacks still run before the check.
				add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'cart_diag_snapshot' ), PHP_INT_MIN, 1 );
				add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'cart_diag_check' ), PHP_INT_MAX, 1 );

				// The sort_by_price() fatal also fires on paths that never run
				// woocommerce_before_calculate_totals (WC_Cart::apply_coupon()
				// validates via `new WC_Discounts( WC()->cart )` before any
				// totals calculation), so capture the fatal itself at shutdown
				// regardless of code path.
				register_shutdown_function( array( __CLASS__, 'cart_diag_shutdown_capture' ) );
			}

			$mode          = self::get_mode();
			$throttle_mode = self::get_throttle_mode();

			// Optional diagnostic, independent of mode/throttle: log the PHP
			// caller of the unscoped "order-notes mega-query". Registered
			// before the early-return so it works even when the guard is
			// otherwise off. Disabled unless explicitly enabled in wp-config.
			if ( self::order_notes_probe_enabled() ) {
				add_action( 'pre_get_comments', array( __CLASS__, 'log_unscoped_order_notes' ), 1 );
			}

			if ( self::MODE_OFF === $mode && self::THROTTLE_MODE_OFF === $throttle_mode ) {
				return;
			}

			if ( self::THROTTLE_MODE_OFF !== $throttle_mode ) {
				add_filter( 'action_scheduler_queue_runner_batch_size', array( __CLASS__, 'filter_queue_runner_batch_size' ), 99 );
				add_filter( 'action_scheduler_queue_runner_time_limit', array( __CLASS__, 'filter_queue_runner_time_limit' ), 99 );
				add_filter( 'action_scheduler_queue_runner_concurrent_batches', array( __CLASS__, 'filter_queue_runner_concurrent_batches' ), 99 );

				// before_execute does per-action DB work; test_observe is a
				// capability probe and must not pay that cost.
				if ( self::THROTTLE_MODE_TEST_OBSERVE !== $throttle_mode ) {
					add_action( 'action_scheduler_before_execute', array( __CLASS__, 'maybe_defer_action_before_execute' ), 1, 2 );
				}
			}

			if ( self::MODE_ENFORCE === $mode ) {
				// v2 drop-in applies SET SESSION on first query (pre-init).
				// We still hook init to refine the limit with the correct
				// per-context tier (admin 45s, checkout 60s, etc.).
				add_action( 'init', array( __CLASS__, 'apply_session_timeout' ), 1 );
				add_action( 'rest_api_init', array( __CLASS__, 'apply_session_timeout' ), 1 );
				add_action( 'admin_init', array( __CLASS__, 'apply_session_timeout' ), 1 );

				add_filter( 'query', array( __CLASS__, 'capture_pending_kill_filter' ), 1 );
				add_action( 'shutdown', array( __CLASS__, 'detect_and_log_kill' ), 0 );
				add_action( 'admin_notices', array( __CLASS__, 'render_admin_search_notice' ) );
			}

			// v2 drop-in: conditional backtracing at 100% replaces SAVEQUERIES.
			// v1 fallback: SAVEQUERIES sampled at OBSERVE_SAMPLE_PCT.
			if ( self::dropin_active() ) {
				if ( self::MODE_OFF !== $mode ) {
					add_action( 'shutdown', array( __CLASS__, 'log_slow_queries' ), 1 );
				}
			} elseif ( self::should_observe_queries( $mode ) ) {
				if ( ! defined( 'SAVEQUERIES' ) ) {
					define( 'SAVEQUERIES', true );
				}
				add_action( 'shutdown', array( __CLASS__, 'log_slow_queries' ), 1 );
			}

			add_action( 'init', array( __CLASS__, 'maybe_run_diagnostic_query' ), 99 );
		}

		/**
		 * Fire a SLEEP() query when ?hcqg_test=1 is present. Admin-only.
		 * Triggers the slow-query logging pipeline for end-to-end validation.
		 */
		public static function maybe_run_diagnostic_query() {
			if ( ! isset( $_GET['hcqg_test'] ) || ! current_user_can( 'manage_options' ) ) {
				return;
			}
			global $wpdb;
			$wpdb->query( 'SELECT SLEEP(6)' );
		}

		/**
		 * Whether the unscoped order-note caller probe is enabled.
		 *
		 * Off unless define( 'HYPERCART_QUERY_GUARD_LOG_ORDER_NOTES', true )
		 * is set in wp-config.php. Also overridable via the
		 * 'hypercart_query_guard_log_order_notes' filter.
		 *
		 * @return bool
		 */
		private static function order_notes_probe_enabled() {
			$enabled = defined( 'HYPERCART_QUERY_GUARD_LOG_ORDER_NOTES' ) && HYPERCART_QUERY_GUARD_LOG_ORDER_NOTES;

			return (bool) apply_filters( 'hypercart_query_guard_log_order_notes', $enabled );
		}

		/**
		 * Diagnostic probe for the "order-notes mega-query".
		 *
		 * WooCommerce's wc_get_order_notes() maps order_id => post_id and then
		 * calls get_comments() with type 'order_note' and no LIMIT. When a
		 * caller invokes it with no order_id / order__in, the query is
		 * unscoped: WordPress gathers the IDs of every order note on the site
		 * and primes the comment cache in one giant
		 * `SELECT wp_comments.* FROM wp_comments WHERE comment_ID IN (...)`,
		 * scanning ~9M rows and growing without bound.
		 *
		 * The slow-query log only records the immediate caller
		 * (wc-order-functions.php), not who called it unscoped. This fires on
		 * pre_get_comments — before the expensive query runs — and records a
		 * full backtrace so the originating caller can be identified.
		 *
		 * Scoped per-order loads (the normal, cheap case) are ignored, so this
		 * logs only the problematic unscoped call. Enabled only when
		 * HYPERCART_QUERY_GUARD_LOG_ORDER_NOTES is truthy.
		 *
		 * @param WP_Comment_Query $query Comment query (passed by reference; not modified).
		 * @return void
		 */
		public static function log_unscoped_order_notes( $query ) {
			if ( ! is_object( $query ) || empty( $query->query_vars ) ) {
				return;
			}

			$vars = $query->query_vars;

			// WooCommerce order notes only.
			if ( ! isset( $vars['type'] ) || 'order_note' !== $vars['type'] ) {
				return;
			}

			// Scoped per-order loads are cheap and expected. Only the unscoped
			// "load every note" call is the performance problem.
			if ( ! empty( $vars['post_id'] ) || ! empty( $vars['post__in'] ) ) {
				return;
			}

			// Bound per-request volume in case a caller loops.
			if ( self::$order_notes_log_count >= self::ORDER_NOTES_LOG_MAX_PER_REQUEST ) {
				return;
			}
			self::$order_notes_log_count++;

			// Caller chain. Skip this handler and the hook-dispatch frames so
			// get_comments() -> wc_get_order_notes() -> originating caller
			// surface at the front of the trace.
			$trace = function_exists( 'wp_debug_backtrace_summary' )
				? wp_debug_backtrace_summary( null, 3, false )
				: array();
			if ( is_array( $trace ) ) {
				$trace = implode( ' < ', $trace );
			}

			self::log(
				'warn',
				array(
					'event'   => 'order_notes_unscoped',
					'context' => self::detect_context(),
					'action'  => isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '',
					'uri'     => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
					'referer' => isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
					'limit'   => isset( $vars['number'] ) ? (int) $vars['number'] : 0,
					'caller'  => self::truncate( (string) $trace, 1200 ),
				)
			);
		}

		/**
		 * Resolve current operating mode.
		 *
		 * @return string
		 */
		private static function get_mode() {
			if ( defined( 'HYPERCART_QUERY_GUARD_MODE' ) ) {
				$mode = HYPERCART_QUERY_GUARD_MODE;
				if ( in_array( $mode, array( self::MODE_OFF, self::MODE_OBSERVE, self::MODE_ENFORCE ), true ) ) {
					return $mode;
				}
			}
			return self::MODE_OBSERVE;
		}

		/**
		 * Resolve current Action Scheduler throttle mode.
		 *
		 * @return string
		 */
		private static function get_throttle_mode() {
			$mode = self::THROTTLE_MODE_OFF;

			if ( defined( 'HYPERCART_QUERY_GUARD_THROTTLE_MODE' ) ) {
				$mode = HYPERCART_QUERY_GUARD_THROTTLE_MODE;
			}

			$mode = apply_filters( 'hypercart_query_guard_throttle_mode', $mode );
			if ( ! in_array( $mode, array( self::THROTTLE_MODE_OFF, self::THROTTLE_MODE_TEST_OBSERVE, self::THROTTLE_MODE_OBSERVE, self::THROTTLE_MODE_ENFORCE ), true ) ) {
				$mode = self::THROTTLE_MODE_OFF;
			}

			$enabled = (bool) apply_filters( 'hypercart_query_guard_throttle_enabled', self::THROTTLE_MODE_OFF !== $mode, $mode );
			if ( ! $enabled ) {
				return self::THROTTLE_MODE_OFF;
			}

			return $mode;
		}

		/**
		 * Decide whether to enable SAVEQUERIES-based observation for this request.
		 * In enforce mode we always observe (cheap, since SET SESSION is the real
		 * protection). In observe mode we sample to bound overhead.
		 *
		 * @param string $mode Query-guard mode.
		 * @return bool
		 */
		private static function should_observe_queries( $mode ) {
			if ( self::MODE_ENFORCE === $mode ) {
				return true;
			}
			if ( self::MODE_OBSERVE !== $mode ) {
				return false;
			}
			// Avoid wp_rand here — it's not loaded this early. random_int is
			// PHP 7+ core; the catch is paranoia for environments without an
			// entropy source (where it's the only built-in that throws).
			try {
				$roll = random_int( 1, 100 );
			} catch ( Exception $e ) {
				$roll = mt_rand( 1, 100 );
			}
			return ( $roll <= self::OBSERVE_SAMPLE_PCT );
		}

		/**
		 * Queue-runner filter: batch size.
		 *
		 * @param int $batch_size
		 * @return int
		 */
		public static function filter_queue_runner_batch_size( $batch_size ) {
			$decision = self::get_throttle_decision();
			return self::apply_throttle_value( 'batch_size', (int) $batch_size, $decision );
		}

		/**
		 * Queue-runner filter: time limit.
		 *
		 * @param int $time_limit
		 * @return int
		 */
		public static function filter_queue_runner_time_limit( $time_limit ) {
			$decision = self::get_throttle_decision();
			return self::apply_throttle_value( 'time_limit', (int) $time_limit, $decision );
		}

		/**
		 * Queue-runner filter: concurrent batches.
		 *
		 * @param int $concurrent_batches
		 * @return int
		 */
		public static function filter_queue_runner_concurrent_batches( $concurrent_batches ) {
			$decision = self::get_throttle_decision();
			return self::apply_throttle_value( 'concurrent_batches', (int) $concurrent_batches, $decision );
		}

		/**
		 * Per-action throttle hook. Runs before Action Scheduler executes the callback.
		 *
		 * @param int    $action_id
		 * @param string $runner_context
		 * @return void
		 */
		public static function maybe_defer_action_before_execute( $action_id, $runner_context ) {
			$decision = self::get_throttle_decision();
			if ( self::THROTTLE_LEVEL_NORMAL === $decision['level'] ) {
				return;
			}

			$action_decision = self::get_action_throttle_decision( (int) $action_id, (string) $runner_context, $decision );
			if ( empty( $action_decision['should_defer'] ) ) {
				return;
			}

			$args        = isset( $action_decision['args'] ) ? $action_decision['args'] : array();
			$max_defers  = (int) apply_filters( 'hypercart_query_guard_max_defer_count', 5 );
			$defer_count = self::get_defer_count( $action_decision['hook'], $args, $action_decision['group'] );
			if ( $max_defers > 0 && $defer_count >= $max_defers ) {
				self::log(
					'info',
					array(
						'event'         => 'as_action_deferral_skipped',
						'reason'        => 'max_defers_reached',
						'action_id'     => (int) $action_id,
						'hook'          => $action_decision['hook'],
						'group'         => $action_decision['group'],
						'priority_tier' => $action_decision['priority_tier'],
						'defer_count'   => $defer_count,
						'max'           => $max_defers,
						'load_level'    => $decision['level'],
					)
				);
				return;
			}

			$rescheduled_action_id = 0;
			if ( self::THROTTLE_MODE_ENFORCE === $decision['effective_mode'] ) {
				$rescheduled_action_id = self::defer_action( (int) $action_id, $action_decision );
				if ( $rescheduled_action_id <= 0 ) {
					return;
				}
				self::increment_defer_count( $action_decision['hook'], $args, $action_decision['group'] );
			}

			// Dedupe per (hook, level) per request to bound log volume during
			// sustained load. Enforce mode emits one record per actual defer
			// because state changed and we want the audit trail; observe mode
			// is just a forecast and one line per hook is enough.
			if ( self::THROTTLE_MODE_OBSERVE === $decision['effective_mode'] ) {
				$dedup_key = $action_decision['hook'] . '@' . $decision['level'];
				if ( ! empty( self::$throttle_runtime['observed_defer_keys'][ $dedup_key ] ) ) {
					return;
				}
				self::$throttle_runtime['observed_defer_keys'][ $dedup_key ] = true;
			}

			self::log(
				'info',
				array(
					'event'                => 'as_action_deferred',
					'action_id'            => (int) $action_id,
					'rescheduled_action_id' => (int) $rescheduled_action_id,
					'hook'                 => $action_decision['hook'],
					'group'                => $action_decision['group'],
					'priority_tier'        => $action_decision['priority_tier'],
					'delay_seconds'        => $action_decision['delay_seconds'],
					'defer_count'          => $defer_count,
					'requested_mode'       => $decision['requested_mode'],
					'effective_mode'       => $decision['effective_mode'],
					'load_level'           => $decision['level'],
					'runner_context'       => (string) $runner_context,
					'detector_mode'        => $decision['metrics']['detector_mode'],
					'threads_running'      => $decision['metrics']['threads_running'],
					'queue_depth'          => $decision['metrics']['queue_depth'],
					'uri'                  => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
				)
			);
		}

		/**
		 * Resolve the throttle decision once per request.
		 *
		 * @return array<string,mixed>
		 */
		private static function get_throttle_decision() {
			if ( isset( self::$throttle_runtime['decision'] ) ) {
				return self::$throttle_runtime['decision'];
			}

			$requested_mode = self::get_throttle_mode();
			$thresholds     = HCQG_Load_Monitor::get_thresholds();
			$cache_backend  = HCQG_Load_Monitor::get_cache_backend();
			$effective_mode = self::get_effective_throttle_mode( $requested_mode, $cache_backend );
			$metrics        = HCQG_Load_Monitor::collect_metrics();
			$state          = HCQG_Load_Monitor::read_state();
			$previous_level = isset( $state['level'] ) ? (string) $state['level'] : self::THROTTLE_LEVEL_NORMAL;
			$previous_at    = isset( $state['changed_at'] ) ? (int) $state['changed_at'] : 0;
			$evaluated      = HCQG_Load_Monitor::evaluate_level( $metrics, $thresholds, $previous_level, $previous_at );
			$now            = time();
			$changed_at     = $evaluated['level'] === $previous_level ? $previous_at : $now;

			$decision = array(
				'requested_mode' => $requested_mode,
				'effective_mode' => $effective_mode,
				'cache_backend'  => $cache_backend,
				'policy'         => self::get_throttle_policy(),
				'metrics'        => $metrics,
				'level'          => $evaluated['level'],
				'raw_level'      => $evaluated['raw_level'],
				'previous_level' => $previous_level,
				'previous_at'    => $previous_at,
				'changed_at'     => $changed_at,
				'context'        => self::detect_context(),
				'blocked_reason' => self::THROTTLE_MODE_ENFORCE === $requested_mode && self::THROTTLE_MODE_ENFORCE !== $effective_mode ? 'persistent_cache_required' : '',
			);

			self::$throttle_runtime['decision'] = $decision;
			HCQG_Load_Monitor::persist_state(
				array(
					'level'      => $decision['level'],
					'changed_at' => $decision['changed_at'],
				)
			);
			self::maybe_log_throttle_events( $decision );

			return $decision;
		}

		/**
		 * Apply a throttled queue-runner setting if enforce mode is active.
		 *
		 * @param string               $field
		 * @param int                  $value
		 * @param array<string,mixed>  $decision
		 * @return int
		 */
		private static function apply_throttle_value( $field, $value, array $decision ) {
			if ( self::THROTTLE_MODE_ENFORCE !== $decision['effective_mode'] ) {
				return $value;
			}

			if ( self::THROTTLE_LEVEL_NORMAL === $decision['level'] ) {
				return $value;
			}

			$policy = isset( $decision['policy'][ $decision['level'] ][ $field ] ) ? (int) $decision['policy'][ $decision['level'] ][ $field ] : $value;
			if ( $policy < 1 ) {
				$policy = 1;
			}

			return min( $value, $policy );
		}

		/**
		 * Default throttle policy, filterable per store.
		 *
		 * @return array<string,array<string,int>>
		 */
		private static function get_throttle_policy() {
			$policy = apply_filters( 'hypercart_query_guard_throttle_policy', self::THROTTLE_POLICY );
			if ( ! is_array( $policy ) ) {
				return self::THROTTLE_POLICY;
			}

			$normalized = self::THROTTLE_POLICY;
			foreach ( $normalized as $level => $fields ) {
				if ( ! isset( $policy[ $level ] ) || ! is_array( $policy[ $level ] ) ) {
					continue;
				}
				foreach ( $fields as $field => $default ) {
					if ( isset( $policy[ $level ][ $field ] ) ) {
						$normalized[ $level ][ $field ] = max( 0, (int) $policy[ $level ][ $field ] );
					}
				}
			}

			return $normalized;
		}

		/**
		 * Default per-action delay matrix, filterable per store.
		 *
		 * Filter contract for `hypercart_query_guard_action_delay_matrix`:
		 *   - Cells are addressed by (load_level, priority_tier). Levels are
		 *     fixed (`elevated`, `critical`); tiers come from the canonical
		 *     HCQG_Priority_Registry::TIERS. Filters can only override
		 *     existing cells — unknown level keys, unknown tier keys, and
		 *     non-array level values are silently ignored.
		 *   - Values are clamped to non-negative integer seconds. Set a cell
		 *     to 0 to mean "run immediately" for that (level, tier) pair.
		 *
		 * @return array<string,array<string,int>>
		 */
		private static function get_action_delay_matrix() {
			$matrix = apply_filters( 'hypercart_query_guard_action_delay_matrix', self::THROTTLE_ACTION_DELAYS );
			if ( ! is_array( $matrix ) ) {
				return self::THROTTLE_ACTION_DELAYS;
			}

			$normalized = self::THROTTLE_ACTION_DELAYS;
			foreach ( $normalized as $level => $tiers ) {
				if ( ! isset( $matrix[ $level ] ) || ! is_array( $matrix[ $level ] ) ) {
					continue;
				}
				foreach ( $tiers as $tier => $delay ) {
					if ( isset( $matrix[ $level ][ $tier ] ) ) {
						$normalized[ $level ][ $tier ] = max( 0, (int) $matrix[ $level ][ $tier ] );
					}
				}
			}

			return $normalized;
		}

		/**
		 * Choose the effective throttle mode after capability checks.
		 *
		 * @param string $requested_mode
		 * @param string $cache_backend
		 * @return string
		 */
		private static function get_effective_throttle_mode( $requested_mode, $cache_backend ) {
			if ( self::THROTTLE_MODE_ENFORCE !== $requested_mode ) {
				return $requested_mode;
			}

			$require_persistent = (bool) apply_filters( 'hypercart_query_guard_throttle_require_persistent_cache', false );
			if ( $require_persistent && ! in_array( $cache_backend, array( 'persistent_object_cache', 'apcu' ), true ) ) {
				return self::THROTTLE_MODE_OBSERVE;
			}

			return $requested_mode;
		}

		/**
		 * Build a per-action deferral decision for the current load state.
		 *
		 * @param int                 $action_id
		 * @param string              $runner_context
		 * @param array<string,mixed> $decision
		 * @return array<string,mixed>
		 */
		private static function get_action_throttle_decision( $action_id, $runner_context, array $decision ) {
			$store = ActionScheduler_Store::instance();

			try {
				$action = $store->fetch_action( $action_id );
			} catch ( Throwable $e ) {
				return array(
					'should_defer' => false,
					'reason'       => 'fetch_failed',
				);
			}

			$hook          = (string) $action->get_hook();
			$group         = (string) $action->get_group();
			$args          = (array) $action->get_args();
			$priority_tier = HCQG_Priority_Registry::get_priority( $hook );

			// Recurring schedules: do not interfere. Cancelling a recurring
			// instance breaks AS's recurrence chain (schedule_next_instance
			// only runs on a successful execution path), and re-rooting the
			// chain at now+delay silently shifts the cadence — for cron
			// schedules in particular, an entire scheduled tick can be missed.
			$schedule = $action->get_schedule();
			if ( method_exists( $schedule, 'is_recurring' ) && $schedule->is_recurring() ) {
				return array(
					'should_defer'  => false,
					'reason'        => 'recurring_schedule',
					'action'        => $action,
					'action_id'     => (int) $action_id,
					'hook'          => $hook,
					'group'         => $group,
					'args'          => $args,
					'priority_tier' => $priority_tier,
				);
			}

			$delay_seconds = self::get_action_delay_seconds( $decision['level'], $priority_tier );

			return array(
				'should_defer'   => $delay_seconds > 0,
				'action'         => $action,
				'action_id'      => (int) $action_id,
				'hook'           => $hook,
				'group'          => $group,
				'args'           => $args,
				'priority_tier'  => $priority_tier,
				'delay_seconds'  => $delay_seconds,
				'runner_context' => $runner_context,
			);
		}

		/**
		 * Resolve the delay for a given load level and priority tier.
		 *
		 * @param string $load_level
		 * @param string $priority_tier
		 * @return int
		 */
		private static function get_action_delay_seconds( $load_level, $priority_tier ) {
			$matrix = self::get_action_delay_matrix();

			if ( ! isset( $matrix[ $load_level ] ) || ! is_array( $matrix[ $load_level ] ) ) {
				return 0;
			}

			return isset( $matrix[ $load_level ][ $priority_tier ] ) ? max( 0, (int) $matrix[ $load_level ][ $priority_tier ] ) : 0;
		}

		/**
		 * Reschedule an action for later, then cancel and unclaim the current one.
		 *
		 * @param int                 $action_id
		 * @param array<string,mixed> $action_decision
		 * @return int New action ID, or 0 on failure.
		 */
		private static function defer_action( $action_id, array $action_decision ) {
			$store  = ActionScheduler_Store::instance();
			$action = $action_decision['action'];

			try {
				$new_action = self::build_deferred_action_clone( $action, (int) $action_decision['delay_seconds'] );
				$new_id     = (int) $store->save_action( $new_action );
				if ( $new_id <= 0 ) {
					self::log(
						'warn',
						array(
							'event'         => 'as_action_defer_failed',
							'reason'        => 'save_returned_zero',
							'action_id'     => (int) $action_id,
							'hook'          => isset( $action_decision['hook'] ) ? $action_decision['hook'] : '',
							'delay_seconds' => isset( $action_decision['delay_seconds'] ) ? (int) $action_decision['delay_seconds'] : 0,
						)
					);
					return 0;
				}

				$store->cancel_action( $action_id );

				return $new_id;
			} catch ( Throwable $e ) {
				self::log(
					'warn',
					array(
						'event'         => 'as_action_defer_failed',
						'reason'        => 'exception',
						'action_id'     => (int) $action_id,
						'hook'          => isset( $action_decision['hook'] ) ? $action_decision['hook'] : '',
						'delay_seconds' => isset( $action_decision['delay_seconds'] ) ? (int) $action_decision['delay_seconds'] : 0,
						'error'         => $e->getMessage(),
					)
				);
			}

			return 0;
		}

		/**
		 * Clone a non-recurring scheduled action with a delayed next run.
		 *
		 * Recurring schedules are filtered out upstream in
		 * get_action_throttle_decision() because cancelling a recurring
		 * instance breaks the recurrence chain; this builder only handles
		 * single-shot / async actions.
		 *
		 * @param ActionScheduler_Action $action
		 * @param int                    $delay_seconds
		 * @return ActionScheduler_Action
		 */
		private static function build_deferred_action_clone( ActionScheduler_Action $action, $delay_seconds ) {
			$run_at       = as_get_datetime_object( time() + max( 0, (int) $delay_seconds ) );
			$new_schedule = new ActionScheduler_SimpleSchedule( $run_at );

			$new_action = new ActionScheduler_Action(
				$action->get_hook(),
				$action->get_args(),
				$new_schedule,
				$action->get_group()
			);

			// Action priority API was added in AS 3.7.0; older WooCommerce
			// installs ship pre-3.7 AS. Guard the calls so we don't fatal.
			if ( method_exists( $action, 'get_priority' ) && method_exists( $new_action, 'set_priority' ) ) {
				$new_action->set_priority( $action->get_priority() );
			}

			return $new_action;
		}

		/**
		 * Per-action defer-count helpers. Cap deferrals per (hook, args, group)
		 * so a deferrable action under sustained critical load eventually runs
		 * instead of starving indefinitely. Backed by the object cache; on
		 * hosts without persistent caching the counter resets per request,
		 * which is fine — the cap is best-effort, not a hard guarantee.
		 */
		const DEFER_COUNT_TTL          = 3600;
		const DEFER_COUNT_KEY_PREFIX   = 'defer_count_';

		private static function defer_count_key( $hook, array $args, $group ) {
			$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $args ) : json_encode( $args );
			$hash    = md5( $hook . '|' . (string) $group . '|' . (string) $encoded );
			return self::DEFER_COUNT_KEY_PREFIX . $hash;
		}

		private static function get_defer_count( $hook, array $args, $group ) {
			return (int) wp_cache_get( self::defer_count_key( $hook, $args, $group ), HCQG_Load_Monitor::CACHE_GROUP );
		}

		private static function increment_defer_count( $hook, array $args, $group ) {
			$key   = self::defer_count_key( $hook, $args, $group );
			$group_key = HCQG_Load_Monitor::CACHE_GROUP;

			wp_cache_add( $key, 0, $group_key, self::DEFER_COUNT_TTL );
			$count = wp_cache_incr( $key, 1, $group_key );

			if ( false === $count ) {
				$count = (int) wp_cache_get( $key, $group_key ) + 1;
				wp_cache_set( $key, $count, $group_key, self::DEFER_COUNT_TTL );
			}

			return (int) $count;
		}

		/**
		 * Emit one-shot throttle logs for this request.
		 *
		 * @param array<string,mixed> $decision
		 * @return void
		 */
		private static function maybe_log_throttle_events( array $decision ) {
			if ( ! empty( self::$throttle_runtime['logged'] ) ) {
				return;
			}

			$payload = array(
				'context'         => $decision['context'],
				'requested_mode'  => $decision['requested_mode'],
				'effective_mode'  => $decision['effective_mode'],
				'level'           => $decision['level'],
				'raw_level'       => $decision['raw_level'],
				'previous_level'  => $decision['previous_level'],
				'cache_backend'   => $decision['cache_backend'],
				'detector_mode'   => $decision['metrics']['detector_mode'],
				'threads_running' => $decision['metrics']['threads_running'],
				'queue_depth'     => $decision['metrics']['queue_depth'],
				'probe_ms'        => $decision['metrics']['probe_ms'],
				'errors'          => $decision['metrics']['errors'],
				'blocked_reason'  => $decision['blocked_reason'],
				'policy'          => $decision['policy'],
				'uri'             => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
			);

			if ( self::THROTTLE_MODE_TEST_OBSERVE === $decision['effective_mode'] ) {
				self::log( 'info', array_merge( array( 'event' => 'as_throttle_capability_test' ), $payload ) );
			}

			if ( $decision['level'] !== $decision['previous_level'] ) {
				self::log(
					'info',
					array_merge(
						array( 'event' => 'load_level_transition' ),
						$payload,
						array(
							'from' => $decision['previous_level'],
							'to'   => $decision['level'],
						)
					)
				);
			}

			if ( self::THROTTLE_MODE_ENFORCE === $decision['effective_mode'] ) {
				self::log( 'info', array_merge( array( 'event' => 'as_throttle_applied' ), $payload ) );
			} elseif ( self::THROTTLE_MODE_OFF !== $decision['effective_mode'] ) {
				self::log( 'info', array_merge( array( 'event' => 'as_throttle_observed' ), $payload ) );
			}

			self::$throttle_runtime['logged'] = true;
		}

		/**
		 * Identify the request context. First match wins, in declaration order.
		 *
		 * @return string Key from self::LIMITS_MS.
		 */
		private static function detect_context() {
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				return 'wp_cli';
			}

			// Action Scheduler must be detected before wp_doing_cron() and
			// wp_doing_ajax() because both AS transports masquerade as those
			// contexts at init priority 1 (before AS hooks have fired). Once
			// apply_session_timeout's static memo caches the wrong tier, AS
			// can't recover the unlimited ceiling later in the run.
			$action = isset( $_REQUEST['action'] ) ? (string) $_REQUEST['action'] : '';
			if (
				did_action( 'action_scheduler_before_process_queue' ) ||
				'as_async_request_queue_runner' === $action ||
				( wp_doing_cron() && 'action_scheduler_run_queue' === $action )
			) {
				return 'action_scheduler';
			}

			if ( wp_doing_cron() ) {
				return 'wp_cron';
			}

			$uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';

			// Checkout detection: the WC checkout AJAX endpoint and the block
			// checkout REST endpoint both need the relaxed 60s ceiling.
			if (
				false !== stripos( $uri, 'wc-ajax=checkout' ) ||
				false !== stripos( $uri, '/wp-json/wc/store/v1/checkout' ) ||
				( defined( 'DOING_CHECKOUT' ) && DOING_CHECKOUT )
			) {
				return 'checkout';
			}

			if ( wp_doing_ajax() ) {
				return 'admin_ajax';
			}
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return 'rest_api';
			}
			if ( is_admin() ) {
				return 'wp_admin';
			}
			return 'frontend';
		}

		/**
		 * Resolve the execution-time ceiling for the current request.
		 *
		 * @return int Milliseconds. 0 = unlimited.
		 */
		private static function get_limit_ms() {
			$context = self::detect_context();
			$limits  = self::LIMITS_MS;

			$limit = isset( $limits[ $context ] ) ? (int) $limits[ $context ] : 30000;

			/**
			 * Filter the per-request execution-time ceiling.
			 *
			 * @param int    $limit_ms Milliseconds. 0 = unlimited.
			 * @param string $context  Detected context key.
			 */
			return (int) apply_filters( 'hypercart_query_guard_limit_ms', $limit, $context );
		}

		/**
		 * Apply MAX_EXECUTION_TIME to the current MySQL session.
		 *
		 * Static $last_dbh memo doubles as a reconnect detector: when WPE's
		 * MySQL proxy rotates the connection, $wpdb->dbh becomes a new
		 * resource/object, the identity check fails, and we re-apply.
		 *
		 * On PHP 8+ mysqli returns objects; === compares object identity, which
		 * is what we want. Do NOT change this to ==.
		 */
		public static function apply_session_timeout() {
			global $wpdb;

			if ( ! isset( $wpdb ) || empty( $wpdb->dbh ) ) {
				return;
			}

			$limit_ms = self::get_limit_ms();
			if ( self::dropin_active() ) {
				static $dropin_last_limit = null;
				if ( $dropin_last_limit === $limit_ms ) {
					return;
				}
				// Delegate all limits, including 0, so unlimited contexts clear
				// the pre-init default applied by the drop-in.
				$wpdb->hcqg_update_limit( $limit_ms );
				$dropin_last_limit = $limit_ms;
				return;
			}

			if ( 0 === $limit_ms ) {
				return; // Unlimited contexts (WP-CLI, Action Scheduler).
			}

			// v1 fallback.
			static $last_dbh   = null;
			static $last_limit = null;

			if ( $last_dbh === $wpdb->dbh && $last_limit === $limit_ms ) {
				return;
			}

			$prev_suppress = $wpdb->suppress_errors( true );
			$wpdb->query( $wpdb->prepare( 'SET SESSION MAX_EXECUTION_TIME = %d', $limit_ms ) );
			$wpdb->suppress_errors( $prev_suppress );

			$last_dbh   = $wpdb->dbh;
			$last_limit = $limit_ms;
		}

		const LARGE_IN_LIST_THRESHOLD = 200;

		/**
		 * 'query' filter callback. Runs before each wpdb::query() executes;
		 * at that point $wpdb->last_error still holds the error from the
		 * previous query (wpdb::flush() clears it later in the same call,
		 * after our filter has run). Pass-through.
		 *
		 * @param string $query
		 * @return string
		 */
		public static function capture_pending_kill_filter( $query ) {
			self::capture_pending_kill();
			return $query;
		}

		/**
		 * Shutdown callback. Catches the final query of the request, which
		 * the 'query' filter never gets a chance to inspect (no subsequent
		 * query exists to trigger it).
		 */
		public static function detect_and_log_kill() {
			self::capture_pending_kill();
		}

		/**
		 * Inspect $wpdb->last_error for the kill signature and emit a
		 * structured log line. Idempotent within a request: tracks
		 * $wpdb->num_queries to avoid logging the same kill twice when
		 * called from both the 'query' filter and the shutdown action.
		 */
		private static function capture_pending_kill() {
			global $wpdb;

			if ( empty( $wpdb ) || empty( $wpdb->last_error ) ) {
				return;
			}

			$num = isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0;
			if ( $num <= self::$last_checked_query_num ) {
				return;
			}
			self::$last_checked_query_num = $num;

			$err = $wpdb->last_error;
			if (
				false === stripos( $err, 'maximum statement execution time' ) &&
				false === stripos( $err, 'query execution was interrupted' )
			) {
				return;
			}

			$uri            = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
			$classification = self::classify_sql( (string) $wpdb->last_query );

			$payload = array(
				'event'                        => 'query_killed',
				'context'                      => self::detect_context(),
				'limit_ms'                     => self::get_limit_ms(),
				'last_query'                   => self::truncate( (string) $wpdb->last_query, 500 ),
				'uri'                          => $uri,
				'user_id'                      => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
				'time'                         => time(),
				'is_admin_ajax'                => self::is_admin_ajax_request( $uri ),
				'table_hint'                   => $classification['table_hint'],
				'is_comment_query'             => $classification['is_comment_query'],
				'has_large_in_list'            => $classification['has_large_in_list'],
				'estimated_in_list_size'       => $classification['estimated_in_list_size'],
				'is_probable_woocommerce'      => $classification['is_probable_woocommerce'],
				'is_probable_order_note_query' => $classification['is_probable_order_note_query'],
			);

			self::log( 'error', $payload );

			// Admin-search recovery: stash the search term so the next admin
			// page render can show a non-misleading notice instead of "no
			// results" (which causes the user to retry, replaying the runaway).
			if ( is_admin() && ! empty( $_GET['s'] ) ) {
				$user_id = $payload['user_id'];
				if ( $user_id > 0 ) {
					set_transient(
						self::ADMIN_NOTICE_TRANSIENT . $user_id,
						array(
							'search'    => sanitize_text_field( wp_unslash( $_GET['s'] ) ),
							'post_type' => isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '',
						),
						60
					);
				}
			}
		}

		/**
		 * Surface a one-shot admin notice when a search was killed. Closes the
		 * retry loop: instead of "no results found" (which prompts the user to
		 * retry and re-trigger the kill), they see "your search timed out."
		 */
		public static function render_admin_search_notice() {
			$user_id = get_current_user_id();
			if ( ! $user_id ) {
				return;
			}
			$key = self::ADMIN_NOTICE_TRANSIENT . $user_id;
			$row = get_transient( $key );
			if ( empty( $row ) || empty( $row['search'] ) ) {
				return;
			}
			delete_transient( $key );

			printf(
				'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Search timed out.', 'hypercart' ),
				esc_html(
					sprintf(
						/* translators: %s: search term */
						__( 'Your search for "%s" took too long and was canceled to keep the site responsive. Try a more specific query, or filter by date range.', 'hypercart' ),
						$row['search']
					)
				)
			);
		}

		/**
		 * On shutdown, walk $wpdb->queries and log anything over the warn
		 * threshold. Active in observe and enforce modes (sampled in observe).
		 */
		public static function log_slow_queries() {
			global $wpdb;

			if ( empty( $wpdb ) ) {
				return;
			}

			// v2 drop-in populates hcqg_slow_queries (already filtered by threshold).
			// v1 reads from $wpdb->queries (populated by SAVEQUERIES).
			if ( self::dropin_active() ) {
				$queries = $wpdb->hcqg_slow_queries;
			} elseif ( defined( 'SAVEQUERIES' ) && SAVEQUERIES && ! empty( $wpdb->queries ) ) {
				$queries = $wpdb->queries;
			} else {
				return;
			}

			if ( empty( $queries ) || ! is_array( $queries ) ) {
				return;
			}

			$threshold_s = self::WARN_THRESHOLD_MS / 1000;
			$context     = self::detect_context();
			$uri         = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

			foreach ( $queries as $row ) {
				// $row = [ $query, $duration_seconds, $callstack, $start_microtime, ... ]
				if ( ! isset( $row[1] ) || $row[1] < $threshold_s ) {
					continue;
				}
				$query_sql      = (string) $row[0];
				$classification = self::classify_sql( $query_sql );
				self::log(
					'warn',
					array(
						'event'                        => 'slow_query',
						'context'                      => $context,
						'duration_ms'                  => (int) ( $row[1] * 1000 ),
						'query'                        => self::truncate( $query_sql, 500 ),
						'caller'                       => isset( $row[2] ) ? self::truncate( (string) $row[2], 500 ) : '',
						'uri'                          => $uri,
						'is_admin_ajax'                => self::is_admin_ajax_request( $uri ),
						'table_hint'                   => $classification['table_hint'],
						'is_comment_query'             => $classification['is_comment_query'],
						'has_large_in_list'            => $classification['has_large_in_list'],
						'estimated_in_list_size'       => $classification['estimated_in_list_size'],
						'is_probable_woocommerce'      => $classification['is_probable_woocommerce'],
						'is_probable_order_note_query' => $classification['is_probable_order_note_query'],
					)
				);
			}
		}

		/**
		 * Classify a SQL string for incident-visibility fields. Returns an array
		 * of cheap, safe heuristics derived from plain string operations.
		 * Only classifies reads (SELECT); writes return all-default values because
		 * MAX_EXECUTION_TIME cannot kill writes by MySQL design.
		 *
		 * Returned keys:
		 *   table_hint                   – 'wp_comments' when the table appears in the SQL, else ''
		 *   is_comment_query             – true when table_hint === 'wp_comments'
		 *   has_large_in_list            – true when an IN list has >= LARGE_IN_LIST_THRESHOLD items
		 *   estimated_in_list_size       – item count (commas + 1) of the first IN list found, else 0
		 *   is_probable_woocommerce      – true when WC table / keyword hints are found
		 *   is_probable_order_note_query – true when it's a wp_comments query with 'order_note'
		 *
		 * @param string $sql Raw SQL string (unsanitised, read-only inspection).
		 * @return array<string, mixed>
		 */
		public static function classify_sql( $sql ) {
			$result = array(
				'table_hint'                   => '',
				'is_comment_query'             => false,
				'has_large_in_list'            => false,
				'estimated_in_list_size'       => 0,
				'is_probable_woocommerce'      => false,
				'is_probable_order_note_query' => false,
			);

			if ( ! is_string( $sql ) || '' === $sql ) {
				return $result;
			}

			// Only classify reads; writes can't be killed by MAX_EXECUTION_TIME anyway.
			if ( 0 !== strncasecmp( ltrim( $sql ), 'SELECT', 6 ) ) {
				return $result;
			}

			// ---- Table hint: wp_comments ----
			if ( false !== stripos( $sql, 'wp_comments' ) ) {
				$result['table_hint']       = 'wp_comments';
				$result['is_comment_query'] = true;
			}

			// ---- Large IN list ----
			// preg_match_all finds every IN ( in the query so that an earlier
			// subquery IN (...) cannot mask a later large literal list — the
			// exact failure mode of the May 2026 incident pattern. We keep the
			// largest list found. Each body is measured with substr_count (O(n),
			// no regex backtracking).
			if ( preg_match_all( '/\bIN\s*\(/i', $sql, $in_matches, PREG_OFFSET_CAPTURE ) ) {
				$max_size = 0;
				foreach ( $in_matches[0] as $in_match ) {
					$open       = $in_match[1] + strlen( $in_match[0] );
					$scan_limit = min( strlen( $sql ) - $open, 524288 ); // cap at 512 KB
					$body_chunk = substr( $sql, $open, $scan_limit );
					$close      = strpos( $body_chunk, ')' );
					if ( false !== $close ) {
						$commas = substr_count( substr( $body_chunk, 0, $close ), ',' );
						if ( $commas + 1 > $max_size ) {
							$max_size = $commas + 1;
						}
					}
				}
				if ( $max_size > 0 ) {
					$result['estimated_in_list_size'] = $max_size;
					$result['has_large_in_list']      = ( $max_size >= self::LARGE_IN_LIST_THRESHOLD );
				}
			}

			// ---- WooCommerce heuristics ----
			foreach ( array( 'woocommerce', 'wc_order', "post_type = 'shop_order'", "post_type='shop_order'" ) as $needle ) {
				if ( false !== stripos( $sql, $needle ) ) {
					$result['is_probable_woocommerce'] = true;
					break;
				}
			}

			// ---- Order-note heuristic ----
			// WC stores order notes as wp_comments rows with comment_type = 'order_note'.
			// The query that caused the May 2026 production incident was exactly this shape.
			if (
				$result['is_comment_query'] &&
				( false !== stripos( $sql, 'order_note' ) || false !== stripos( $sql, 'order-note' ) )
			) {
				$result['is_probable_order_note_query'] = true;
				$result['is_probable_woocommerce']      = true;
			}

			return $result;
		}

		/**
		 * Return true when the given request URI routes through admin-ajax.php.
		 *
		 * @param string $uri Value of $_SERVER['REQUEST_URI'].
		 * @return bool
		 */
		public static function is_admin_ajax_request( $uri ) {
			return false !== strpos( (string) $uri, 'admin-ajax.php' );
		}

		// ================================================================
		// Cart Type Diagnostic
		// ================================================================

		/**
		 * Whether the cart type diagnostic is enabled.
		 *
		 * Constant-first with a filter override, mirroring the throttle
		 * gating pattern. Evaluated once at mu-plugin load, so a filter
		 * override must be registered from wp-config.php or an
		 * earlier-loading mu-plugin.
		 *
		 * @return bool
		 */
		private static function cart_diag_enabled() {
			$enabled = defined( 'HYPERCART_CART_TYPE_DIAGNOSTIC' ) && HYPERCART_CART_TYPE_DIAGNOSTIC;
			if ( function_exists( 'apply_filters' ) ) {
				$enabled = (bool) apply_filters( 'hypercart_cart_type_diagnostic_enabled', $enabled );
			}
			return $enabled;
		}

		/**
		 * Early snapshot (priority PHP_INT_MIN): record cart item quantity,
		 * price, and discounted_price before other hook callbacks run.
		 *
		 * All diagnostic entry points swallow Throwables: this code runs on
		 * production checkout against data known to be malformed in unknown
		 * ways, and must never become a fatal of its own.
		 *
		 * @param WC_Cart|mixed $cart
		 */
		public static function cart_diag_snapshot( $cart ) {
			try {
				self::cart_diag_snapshot_body( $cart );
			} catch ( Throwable $t ) {
				self::cart_diag_note_internal_failure( $t );
			}
		}

		/**
		 * Late check (priority PHP_INT_MAX): compare current cart item
		 * values against the early snapshot and log every non-numeric
		 * quantity/price/discounted_price with origin attribution and the
		 * callback lists needed to identify the offending plugin.
		 *
		 * @param WC_Cart|mixed $cart
		 */
		public static function cart_diag_check( $cart ) {
			try {
				self::cart_diag_check_body( $cart );
			} catch ( Throwable $t ) {
				self::cart_diag_note_internal_failure( $t );
			}
		}

		/**
		 * Shutdown-time fatal catcher. The sort_by_price() TypeError can
		 * fire on paths that never run the instrumented hook, so this is
		 * the guaranteed capture regardless of where corruption entered.
		 */
		public static function cart_diag_shutdown_capture() {
			try {
				$err = error_get_last();
				if ( ! is_array( $err ) || ! isset( $err['type'], $err['message'] ) ) {
					return;
				}
				if ( ! in_array( (int) $err['type'], array( E_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
					return;
				}

				$cart = null;
				if ( function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->cart ) && is_object( WC()->cart ) ) {
					$cart = WC()->cart;
				}

				self::cart_diag_capture_fatal( $err, $cart );
			} catch ( Throwable $t ) {
				self::cart_diag_note_internal_failure( $t );
			}
		}

		/**
		 * Testable core of the shutdown capture: match the cart/discount
		 * type fatal and dump per-item type data from the in-memory cart.
		 *
		 * Public for testability, and self-guarded like the other entry
		 * points — it never throws, no matter who calls it.
		 *
		 * @param array       $err  error_get_last()-shaped array.
		 * @param object|null $cart Cart object, if one is available.
		 * @return bool Whether the fatal matched and was logged.
		 */
		public static function cart_diag_capture_fatal( $err, $cart ) {
			try {
				return self::cart_diag_capture_fatal_body( $err, $cart );
			} catch ( Throwable $t ) {
				self::cart_diag_note_internal_failure( $t );
				return false;
			}
		}

		/**
		 * @param array       $err  error_get_last()-shaped array.
		 * @param object|null $cart Cart object, if one is available.
		 * @return bool Whether the fatal matched and was logged.
		 */
		private static function cart_diag_capture_fatal_body( $err, $cart ) {
			$message = isset( $err['message'] ) ? (string) $err['message'] : '';
			$file    = isset( $err['file'] ) ? (string) $err['file'] : '';

			if ( false === strpos( $message, 'Unsupported operand types' ) ) {
				return false;
			}

			// Only fatals raised from WooCommerce cart/discount internals.
			$haystack = $file . ' ' . $message;
			if ( false === stripos( $haystack, 'wc-discounts' )
				&& false === stripos( $haystack, 'wc-cart' )
				&& false === stripos( $haystack, 'sort_by_price' )
				&& false === stripos( $haystack, 'woocommerce' ) ) {
				return false;
			}

			$items = array();
			if ( is_object( $cart ) ) {
				// Prefer the raw property over get_cart(): no filter chain
				// runs during shutdown after a fatal.
				$contents = isset( $cart->cart_contents ) && is_array( $cart->cart_contents ) ? $cart->cart_contents : null;
				if ( null === $contents && is_callable( array( $cart, 'get_cart' ) ) ) {
					$contents = $cart->get_cart();
				}
				if ( is_array( $contents ) ) {
					foreach ( $contents as $key => $item ) {
						if ( ! is_array( $item ) ) {
							$items[] = array(
								'cart_key'  => substr( (string) $key, 0, 12 ),
								'item_type' => gettype( $item ),
							);
							continue;
						}
						$has_qty = array_key_exists( 'quantity', $item );
						$qty     = $has_qty ? $item['quantity'] : null;
						$items[] = array(
							'cart_key'              => substr( (string) $key, 0, 12 ),
							'product_id'            => ( isset( $item['product_id'] ) && is_scalar( $item['product_id'] ) ) ? $item['product_id'] : 0,
							'variation_id'          => ( isset( $item['variation_id'] ) && is_scalar( $item['variation_id'] ) ) ? $item['variation_id'] : 0,
							'quantity'              => $has_qty ? self::cart_diag_safe_value( $qty ) : '{missing}',
							'quantity_type'         => $has_qty ? gettype( $qty ) : 'missing',
							'price'                 => self::cart_diag_safe_value( self::cart_diag_item_price( $item, 'edit' ) ),
							'discounted_price_type' => array_key_exists( 'discounted_price', $item ) ? gettype( $item['discounted_price'] ) : 'absent',
						);
					}
				}
			}

			$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

			self::log( 'error', array(
				'event'           => 'cart_fatal_captured',
				'fatal_message'   => self::truncate( $message, 500 ),
				'fatal_file'      => self::truncate( $file . ':' . ( isset( $err['line'] ) ? (int) $err['line'] : 0 ), 200 ),
				'cart_items'      => $items,
				'applied_coupons' => self::cart_diag_applied_coupons( $cart ),
				'user_id'         => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
				'callbacks'       => self::cart_diag_callback_map(),
				'uri'             => $uri,
				'context'         => self::detect_context(),
				'is_admin_ajax'   => self::is_admin_ajax_request( $uri ),
			) );

			return true;
		}

		/**
		 * @param WC_Cart|mixed $cart
		 */
		private static function cart_diag_snapshot_body( $cart ) {
			if ( self::$cart_diag_log_count >= self::CART_DIAG_MAX_LOGS ) {
				return; // Log budget exhausted — skip the per-item work.
			}

			// Duck-typed: third-party code occasionally fires this hook
			// argless, and the diagnostic must not assume WC_Cart exists.
			if ( ! is_object( $cart ) || ! is_callable( array( $cart, 'get_cart' ) ) ) {
				return;
			}

			// Re-entrancy guard: a callback that calls calculate_totals()
			// mid-hook re-fires this hook nested; overwriting the outer
			// snapshot with post-corruption values would flip attribution
			// to 'upstream'. Keep the outer (earliest) snapshot instead.
			if ( self::$cart_diag_in_progress ) {
				return;
			}
			self::$cart_diag_in_progress = true;

			self::$cart_diag_snapshot = array();

			$items = $cart->get_cart();
			if ( ! is_array( $items ) ) {
				return;
			}

			foreach ( $items as $key => $item ) {
				if ( ! is_array( $item ) ) {
					continue; // Non-array item row: recorded as corruption by the check pass.
				}

				self::$cart_diag_snapshot[ $key ] = array(
					'quantity'         => array_key_exists( 'quantity', $item ) ? $item['quantity'] : null,
					// 'edit' context skips the product-price filter chain: no
					// third-party code runs and no observer effect, while
					// set_price() writes are still visible. The check pass
					// reads the filtered 'view' value WC actually consumes.
					'price'            => self::cart_diag_item_price( $item, 'edit' ),
					'discounted_price' => array_key_exists( 'discounted_price', $item ) ? $item['discounted_price'] : null,
					'has_discounted'   => array_key_exists( 'discounted_price', $item ),
				);
			}
		}

		/**
		 * @param WC_Cart|mixed $cart
		 */
		private static function cart_diag_check_body( $cart ) {
			// The snapshot/check pair completed (or never started); either
			// way the next firing may take a fresh snapshot.
			self::$cart_diag_in_progress = false;

			if ( self::$cart_diag_log_count >= self::CART_DIAG_MAX_LOGS ) {
				return;
			}

			if ( ! is_object( $cart ) || ! is_callable( array( $cart, 'get_cart' ) ) ) {
				return;
			}

			$items = $cart->get_cart();
			if ( ! is_array( $items ) ) {
				return;
			}

			$corrupted = array();

			foreach ( $items as $key => $item ) {
				$early = isset( self::$cart_diag_snapshot[ $key ] ) ? self::$cart_diag_snapshot[ $key ] : null;

				if ( ! is_array( $item ) ) {
					// The whole item row was replaced with a non-array value.
					$corrupted[] = self::cart_diag_entry( 'item', $key, $item, $item, null !== $early, null );
					continue;
				}

				// WC 10.8.x: only a non-numeric string quantity can raise the
				// float/string TypeError in WC_Discounts::sort_by_price() —
				// price is float-cast upstream. The price/discounted_price
				// findings below are context, not the fatal's cause.
				if ( ! array_key_exists( 'quantity', $item ) ) {
					$row                  = self::cart_diag_entry( 'quantity', $key, $item, null, null !== $early, $early ? $early['quantity'] : null );
					$row['current_value'] = '{missing}';
					$row['current_type']  = 'missing';
					$corrupted[]          = $row;
				} elseif ( ! is_numeric( $item['quantity'] ) ) {
					$corrupted[] = self::cart_diag_entry(
						'quantity',
						$key,
						$item,
						$item['quantity'],
						null !== $early,
						$early ? $early['quantity'] : null
					);
				}

				// 'view' context: the filtered value WC_Discounts consumes.
				// NOTE: the snapshot stored the raw 'edit' value, so on price
				// rows early_value/current_value mix contexts: a view-only
				// filter corruption reads as 'hook_callback' even if that
				// filter predates the hook. Treat corrupted_by on price rows
				// as a hint — quantity rows carry the authoritative signal.
				$price = self::cart_diag_item_price( $item, 'view' );
				if ( null !== $price && ! is_numeric( $price ) ) {
					$corrupted[] = self::cart_diag_entry( 'price', $key, $item, $price, null !== $early, $early ? $early['price'] : null );
				}

				if ( array_key_exists( 'discounted_price', $item ) && null !== $item['discounted_price'] && ! is_numeric( $item['discounted_price'] ) ) {
					$had_early_field = $early && ! empty( $early['has_discounted'] );
					$corrupted[]     = self::cart_diag_entry(
						'discounted_price',
						$key,
						$item,
						$item['discounted_price'],
						$had_early_field,
						$had_early_field ? $early['discounted_price'] : null
					);
				}
			}

			if ( empty( $corrupted ) ) {
				return;
			}

			// The hook fires several times per request; identical findings
			// are logged once per process, new findings get their own event
			// up to CART_DIAG_MAX_LOGS.
			$sig_parts = array();
			foreach ( $corrupted as $entry ) {
				$sig_parts[] = $entry['field'] . '|' . $entry['cart_key'] . '|' . $entry['current_type'] . '|' . $entry['current_value'];
			}
			$sig = md5( implode( "\n", $sig_parts ) );
			if ( isset( self::$cart_diag_logged_sigs[ $sig ] ) ) {
				return;
			}
			self::$cart_diag_logged_sigs[ $sig ] = true;
			self::$cart_diag_log_count++;

			$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

			self::log( 'error', array(
				'event'           => 'cart_type_corruption',
				'corrupted'       => $corrupted,
				'cart_item_count' => count( $items ),
				'applied_coupons' => self::cart_diag_applied_coupons( $cart ),
				'user_id'         => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
				'callbacks'       => self::cart_diag_callback_map(),
				'uri'             => $uri,
				'context'         => self::detect_context(),
				'is_admin_ajax'   => self::is_admin_ajax_request( $uri ),
			) );
		}

		/**
		 * Build one corruption record.
		 *
		 * Attribution: 'upstream' = already bad in the snapshot (session,
		 * add-to-cart, or a filter predating the hook); 'hook_callback' =
		 * clean at snapshot time, bad now; 'added_during_hook' = the item
		 * had no snapshot entry (e.g. a BOGO/free-gift callback added it).
		 *
		 * @param string $field       Which cart item field is non-numeric.
		 * @param string $key         Cart item key.
		 * @param mixed  $item        Cart item row (usually an array).
		 * @param mixed  $value       The corrupted value.
		 * @param bool   $has_early   Whether snapshot data exists for the field.
		 * @param mixed  $early_value Snapshot value for the field.
		 * @return array
		 */
		private static function cart_diag_entry( $field, $key, $item, $value, $has_early, $early_value ) {
			if ( ! $has_early ) {
				$origin = 'added_during_hook';
			} elseif ( is_numeric( $early_value ) ) {
				$origin = 'hook_callback';
			} else {
				$origin = 'upstream';
			}

			$product_type = '';
			if ( is_array( $item ) && isset( $item['data'] ) && is_object( $item['data'] ) && is_callable( array( $item['data'], 'get_type' ) ) ) {
				$type_val     = $item['data']->get_type();
				$product_type = is_string( $type_val ) ? $type_val : gettype( $type_val );
			}

			return array(
				'field'         => $field,
				'cart_key'      => substr( (string) $key, 0, 12 ),
				'product_id'    => ( is_array( $item ) && isset( $item['product_id'] ) && is_scalar( $item['product_id'] ) ) ? $item['product_id'] : 0,
				'variation_id'  => ( is_array( $item ) && isset( $item['variation_id'] ) && is_scalar( $item['variation_id'] ) ) ? $item['variation_id'] : 0,
				'product_type'  => $product_type,
				'current_value' => self::cart_diag_safe_value( $value ),
				'current_type'  => gettype( $value ),
				'early_value'   => $has_early ? self::cart_diag_safe_value( $early_value ) : null,
				'early_type'    => $has_early ? gettype( $early_value ) : null,
				'corrupted_by'  => $origin,
			);
		}

		/**
		 * Read a cart item's product price without letting a broken product
		 * object or throwing price filter take the diagnostic down.
		 *
		 * @param array|mixed $item    Cart item row.
		 * @param string      $context 'view' (filtered — what WC consumes) or 'edit' (raw prop).
		 * @return mixed Null when no readable product object is present; a
		 *               '{get_price threw …}' marker when the read throws
		 *               (itself diagnostic signal — non-numeric, so flagged).
		 */
		private static function cart_diag_item_price( $item, $context ) {
			if ( ! is_array( $item ) || ! isset( $item['data'] ) || ! is_object( $item['data'] ) || ! is_callable( array( $item['data'], 'get_price' ) ) ) {
				return null;
			}
			try {
				return $item['data']->get_price( $context );
			} catch ( Throwable $t ) {
				return '{get_price threw ' . get_class( $t ) . '}';
			}
		}

		/**
		 * Render any value as a short, log-safe string. A bare (string)
		 * cast fatals on objects without __toString — exactly the malformed
		 * shapes this diagnostic exists to record — so never cast blindly.
		 *
		 * @param mixed $value
		 * @return string
		 */
		private static function cart_diag_safe_value( $value ) {
			if ( null === $value ) {
				return '{null}';
			}
			if ( is_bool( $value ) ) {
				return $value ? '{true}' : '{false}';
			}
			if ( is_scalar( $value ) ) {
				return self::truncate( (string) $value, 80 );
			}
			if ( is_object( $value ) ) {
				return '{object:' . get_class( $value ) . '}';
			}
			if ( is_array( $value ) ) {
				return self::truncate( '{array:' . self::encode_log_payload( $value ) . '}', 80 );
			}
			return '{' . gettype( $value ) . '}';
		}

		/**
		 * Applied coupon codes, capped and rendered log-safe. The
		 * sort_by_price() fatal only occurs while a coupon is being
		 * applied, so the coupon list is primary reproduction context.
		 *
		 * @param object|mixed $cart
		 * @return string[]
		 */
		private static function cart_diag_applied_coupons( $cart ) {
			if ( ! is_object( $cart ) || ! is_callable( array( $cart, 'get_applied_coupons' ) ) ) {
				return array();
			}
			try {
				$coupons = $cart->get_applied_coupons();
			} catch ( Throwable $t ) {
				return array( '{get_applied_coupons threw ' . get_class( $t ) . '}' );
			}
			if ( ! is_array( $coupons ) ) {
				return array();
			}
			$out = array();
			foreach ( array_slice( $coupons, 0, 20 ) as $code ) {
				$out[] = self::cart_diag_safe_value( $code );
			}
			return $out;
		}

		/**
		 * Callback lists for every hook through which cart item values can
		 * be written or rewritten. 'upstream' corruption typically enters
		 * via the session/product filters, not the totals hook itself.
		 *
		 * @return array<string, string[]>
		 */
		private static function cart_diag_callback_map() {
			$hooks = array(
				'woocommerce_before_calculate_totals',
				'woocommerce_get_cart_item_from_session',
				'woocommerce_get_cart_contents',
				'woocommerce_add_cart_item',
				'woocommerce_product_get_price',
				'woocommerce_product_variation_get_price',
			);

			$map = array();
			foreach ( $hooks as $hook_name ) {
				$callbacks = self::enumerate_hook_callbacks( $hook_name );
				if ( ! empty( $callbacks ) ) {
					$map[ $hook_name ] = $callbacks;
				}
			}
			return $map;
		}

		/**
		 * Record (once per process) that the diagnostic itself failed. The
		 * diagnostic must never take down the cart it is observing, so all
		 * entry points swallow Throwables and leave a single breadcrumb.
		 * Deliberately bypasses self::log() in case log() is implicated.
		 *
		 * @param Throwable $t
		 */
		private static function cart_diag_note_internal_failure( $t ) {
			static $noted = false;
			if ( $noted ) {
				return;
			}
			$noted = true;
			error_log( '[hypercart_query_guard][error] cart_diag internal failure: ' . get_class( $t ) . ': ' . $t->getMessage() );
		}

		/**
		 * List all registered callbacks for a hook, with priorities.
		 *
		 * @param string $hook_name
		 * @return string[]  e.g. ['10:my_function', '30:MyClass::method']
		 */
		private static function enumerate_hook_callbacks( $hook_name ) {
			global $wp_filter;

			// isset() on the property is false for pre-4.7-style plain-array
			// rows as well as missing hooks — both return empty safely.
			if ( ! isset( $wp_filter[ $hook_name ]->callbacks ) || ! is_array( $wp_filter[ $hook_name ]->callbacks ) ) {
				return array();
			}

			$list = array();
			foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => $hooks ) {
				if ( ! is_array( $hooks ) ) {
					continue;
				}
				foreach ( $hooks as $hook ) {
					$fn = isset( $hook['function'] ) ? $hook['function'] : null;
					if ( is_string( $fn ) ) {
						$name = $fn;
					} elseif ( is_array( $fn ) ) {
						$cls  = isset( $fn[0] ) ? ( is_object( $fn[0] ) ? get_class( $fn[0] ) : ( is_string( $fn[0] ) ? $fn[0] : gettype( $fn[0] ) ) ) : '?';
						$mth  = ( isset( $fn[1] ) && is_string( $fn[1] ) ) ? $fn[1] : '?';
						$name = $cls . '::' . $mth;
					} elseif ( $fn instanceof Closure ) {
						try {
							$ref  = new ReflectionFunction( $fn );
							$file = $ref->getFileName();
							// getFileName() is false for closures over internal
							// functions (first-class callable syntax).
							$name = $file ? '{closure@' . basename( $file ) . ':' . (int) $ref->getStartLine() . '}' : '{closure:internal}';
						} catch ( ReflectionException $e ) {
							$name = '{closure}';
						}
					} elseif ( is_object( $fn ) ) {
						$name = '{invokable:' . get_class( $fn ) . '}';
					} else {
						$name = '{unknown:' . gettype( $fn ) . '}';
					}
					$list[] = $priority . ':' . $name;
				}
			}

			// Keep individual log entries bounded on hook-heavy sites.
			if ( count( $list ) > 60 ) {
				$extra = count( $list ) - 60;
				$list  = array_slice( $list, 0, 60 );
				$list[] = '+' . $extra . ' more';
			}

			return $list;
		}

		/**
		 * Centralized log emitter. Prefers Hypercart_Logger if present (the
		 * file-based logger provided by the Hypercart Helper plugin),
		 * falls back to error_log so this MU-plugin works standalone.
		 *
		 * @param string $level   'info' | 'warn' | 'error'
		 * @param array  $payload Structured fields.
		 */
		private static function log( $level, array $payload ) {
			$filtered = apply_filters( 'hypercart_query_guard_log_payload', $payload, $level );
			if ( is_array( $filtered ) && ! empty( $filtered ) ) {
				$payload = $filtered;
			}
			$message = self::encode_log_payload( $payload );

			if ( class_exists( 'Hypercart_Logger' ) ) {
				if ( 'error' === $level && method_exists( 'Hypercart_Logger', 'error' ) ) {
					Hypercart_Logger::error( 'query_guard', self::get_logger_payload_for_method( 'Hypercart_Logger', 'error', $payload, $message ) );
					return;
				}
				if ( 'info' === $level && method_exists( 'Hypercart_Logger', 'info' ) ) {
					Hypercart_Logger::info( 'query_guard', self::get_logger_payload_for_method( 'Hypercart_Logger', 'info', $payload, $message ) );
					return;
				}
				// The Hypercart Helper logger exposes warning(); older/string-only
				// loggers may expose warn(). Prefer warning(), fall back to warn().
				if ( method_exists( 'Hypercart_Logger', 'warning' ) ) {
					Hypercart_Logger::warning( 'query_guard', self::get_logger_payload_for_method( 'Hypercart_Logger', 'warning', $payload, $message ) );
					return;
				}
				if ( method_exists( 'Hypercart_Logger', 'warn' ) ) {
					Hypercart_Logger::warn( 'query_guard', self::get_logger_payload_for_method( 'Hypercart_Logger', 'warn', $payload, $message ) );
					return;
				}
			}
			// Fallback: structured single-line JSON for grep-ability.
			error_log( '[hypercart_query_guard][' . $level . '] ' . $message );
		}

		/**
		 * Preserve structured payloads for legacy logger signatures while
		 * serializing for string-only logger APIs.
		 *
		 * @param string $class_name
		 * @param string $method
		 * @param array  $payload
		 * @param string $message
		 * @return array|string
		 */
		private static function get_logger_payload_for_method( $class_name, $method, array $payload, $message ) {
			try {
				$reflection = new ReflectionMethod( $class_name, $method );
				$params     = $reflection->getParameters();
				if ( ! isset( $params[1] ) ) {
					return $message;
				}

				if ( self::reflection_type_allows_array( $params[1]->getType() ) ) {
					return $payload;
				}
			} catch ( ReflectionException $exception ) {
				return $message;
			}

			return $message;
		}

		/**
		 * Determine whether a reflected parameter type accepts array payloads.
		 *
		 * @param ReflectionType|null $type
		 * @return bool
		 */
		private static function reflection_type_allows_array( $type ) {
			if ( null === $type ) {
				return true;
			}

			if ( $type instanceof ReflectionNamedType ) {
				return in_array( $type->getName(), array( 'array', 'iterable', 'mixed' ), true );
			}

			if ( $type instanceof ReflectionUnionType ) {
				foreach ( $type->getTypes() as $union_type ) {
					if ( self::reflection_type_allows_array( $union_type ) ) {
						return true;
					}
				}
			}

			return false;
		}

		/**
		 * Serialize structured payloads for string-only logger sinks.
		 *
		 * @param array $payload Structured fields.
		 * @return string
		 */
		private static function encode_log_payload( array $payload ) {
			$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload );
			if ( is_string( $encoded ) ) {
				return $encoded;
			}

			return '[hypercart_query_guard] failed to encode log payload';
		}

		/**
		 * Safe truncation for log payloads. Avoids bloating logs with 78KB
		 * IN()-clause queries (the Facebook BG sync pattern).
		 *
		 * @param string $s
		 * @param int    $max
		 * @return string
		 */
		private static function truncate( $s, $max ) {
			if ( strlen( $s ) <= $max ) {
				return $s;
			}
			return substr( $s, 0, $max ) . '…[truncated]';
		}
	}

	Hypercart_Query_Guard::init();
}
