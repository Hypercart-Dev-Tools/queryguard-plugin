<?php
/**
 * Plugin Name:       Hypercart Query Guard
 * Plugin URI:        https://hypercart.io
 * Description:       PHP-side circuit breaker that enforces MySQL MAX_EXECUTION_TIME on read queries to prevent runaway SELECTs from saturating the pod. Tiered limits per request context, observe-mode for safe rollout, automatic re-application on connection rotation, and admin-search timeout fallback.
 * Version:           1.0.0
 * Author:            Hypercart / Neochrome
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP:      7.4
 *
 * Drop in:           wp-content/mu-plugins/hypercart-query-guard.php
 *
 * Mode control:      define( 'HYPERCART_QUERY_GUARD_MODE', 'observe' );
 *                    Modes: 'off' | 'observe' | 'enforce' (default: 'observe')
 *
 * AS throttle:       define( 'HYPERCART_QUERY_GUARD_THROTTLE_MODE', 'test_observe' );
 *                    Modes: 'off' | 'test_observe' | 'observe' | 'enforce'
 *                    (default: 'off')
 *
 * Future v2:         Move SET SESSION application to a wp-content/db.php drop-in
 *                    so the autoloaded-options preload and everything on
 *                    muplugins_loaded / plugins_loaded / setup_theme is also
 *                    protected. v1 (this file) hooks 'init' priority 1, so
 *                    queries fired before then — wp_load_alloptions(),
 *                    auth/usermeta lookups, WC session bootstrap — run
 *                    without a ceiling. See README "Limitations and caveats"
 *                    for signals that indicate v2 is needed.
 *
 * @package Hypercart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
		 * State storage for throttle hysteresis.
		 */
		const THROTTLE_STATE_OPTION = 'hcqg_throttle_state';
		const THROTTLE_CACHE_GROUP  = 'hypercart_query_guard';
		const THROTTLE_CACHE_KEY    = 'throttle_state';

		/**
		 * Default throttle thresholds.
		 */
		const THROTTLE_THRESHOLDS = array(
			'cache_ttl_seconds'             => 5,
			'level_min_dwell_seconds'       => 30,
			'threads_running_elevated'      => 5,
			'threads_running_critical'      => 15,
			'threads_running_elevated_exit' => 3,
			'threads_running_critical_exit' => 10,
			'queue_depth_elevated'          => 100,
			'queue_depth_critical'          => 500,
			'queue_depth_elevated_exit'     => 50,
			'queue_depth_critical_exit'     => 250,
		);

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
			'admin_ajax'       => 20000,
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
		 * Cached persisted throttle state.
		 *
		 * @var array<string,mixed>|null
		 */
		private static $throttle_state_cache = null;

		/**
		 * Bootstrap.
		 */
		public static function init() {
			$mode          = self::get_mode();
			$throttle_mode = self::get_throttle_mode();

			if ( self::MODE_OFF === $mode && self::THROTTLE_MODE_OFF === $throttle_mode ) {
				return;
			}

			if ( self::THROTTLE_MODE_OFF !== $throttle_mode ) {
				add_filter( 'action_scheduler_queue_runner_batch_size', array( __CLASS__, 'filter_queue_runner_batch_size' ), 99 );
				add_filter( 'action_scheduler_queue_runner_time_limit', array( __CLASS__, 'filter_queue_runner_time_limit' ), 99 );
				add_filter( 'action_scheduler_queue_runner_concurrent_batches', array( __CLASS__, 'filter_queue_runner_concurrent_batches' ), 99 );
			}

			// Apply the SET SESSION early. Priority 1 on 'init' is as early as
			// reliably available without a db.php drop-in.
			if ( self::MODE_ENFORCE === $mode ) {
				add_action( 'init', array( __CLASS__, 'apply_session_timeout' ), 1 );
				add_action( 'rest_api_init', array( __CLASS__, 'apply_session_timeout' ), 1 );
				add_action( 'admin_init', array( __CLASS__, 'apply_session_timeout' ), 1 );

				// Catch the kill, log it, and surface a recovery notice in admin.
				// The 'query' filter fires before each subsequent query, so we
				// can capture the previous query's error before wpdb::flush()
				// clears it. Without this, multiple kills in one request would
				// collapse into a single log line (the last one). Shutdown is
				// the fallback for the final query of the request.
				add_filter( 'query', array( __CLASS__, 'capture_pending_kill_filter' ), 1 );
				add_action( 'shutdown', array( __CLASS__, 'detect_and_log_kill' ), 0 );
				add_action( 'admin_notices', array( __CLASS__, 'render_admin_search_notice' ) );
			}

			// Observe mode (and enforce mode, additively) logs slow queries via
			// SAVEQUERIES. Sampled to keep memory overhead bounded.
			if ( self::should_observe_queries( $mode ) ) {
				if ( ! defined( 'SAVEQUERIES' ) ) {
					define( 'SAVEQUERIES', true );
				}
				add_action( 'shutdown', array( __CLASS__, 'log_slow_queries' ), 1 );
			}
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
		 * Resolve the throttle decision once per request.
		 *
		 * @return array<string,mixed>
		 */
		private static function get_throttle_decision() {
			if ( isset( self::$throttle_runtime['decision'] ) ) {
				return self::$throttle_runtime['decision'];
			}

			$requested_mode = self::get_throttle_mode();
			$thresholds     = self::get_throttle_thresholds();
			$cache_backend  = self::get_throttle_cache_backend();
			$effective_mode = self::get_effective_throttle_mode( $requested_mode, $cache_backend );
			$metrics        = self::collect_throttle_metrics();
			$state          = self::read_throttle_state();
			$previous_level = isset( $state['level'] ) ? (string) $state['level'] : self::THROTTLE_LEVEL_NORMAL;
			$previous_at    = isset( $state['changed_at'] ) ? (int) $state['changed_at'] : 0;
			$evaluated      = self::evaluate_throttle_level( $metrics, $thresholds, $previous_level, $previous_at );
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
			self::persist_throttle_state( $decision );
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
		 * Default throttle thresholds, filterable for host tuning.
		 *
		 * @return array<string,int>
		 */
		private static function get_throttle_thresholds() {
			$thresholds = apply_filters( 'hypercart_query_guard_load_thresholds', self::THROTTLE_THRESHOLDS );
			if ( ! is_array( $thresholds ) ) {
				$thresholds = self::THROTTLE_THRESHOLDS;
			}

			$defaults = self::THROTTLE_THRESHOLDS;
			$merged   = array_merge( $defaults, $thresholds );
			foreach ( $merged as $key => $value ) {
				$merged[ $key ] = max( 0, (int) $value );
			}

			return $merged;
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
			return $policy;
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
		 * Identify the best available cross-request state backend.
		 *
		 * @return string
		 */
		private static function get_throttle_cache_backend() {
			if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
				return 'persistent_object_cache';
			}

			if ( self::is_apcu_available() ) {
				return 'apcu';
			}

			return 'db_fallback';
		}

		/**
		 * Check whether APCu is available for request-local persistence.
		 *
		 * @return bool
		 */
		private static function is_apcu_available() {
			if ( ! function_exists( 'apcu_fetch' ) || ! function_exists( 'apcu_store' ) ) {
				return false;
			}

			if ( PHP_SAPI === 'cli' && ! (bool) ini_get( 'apc.enable_cli' ) ) {
				return false;
			}

			return (bool) ini_get( 'apc.enabled' );
		}

		/**
		 * Read the last persisted throttle state.
		 *
		 * @return array<string,mixed>
		 */
		private static function read_throttle_state() {
			if ( null !== self::$throttle_state_cache ) {
				return self::$throttle_state_cache;
			}

			$backend = self::get_throttle_cache_backend();
			$state   = false;

			if ( 'persistent_object_cache' === $backend ) {
				$state = wp_cache_get( self::THROTTLE_CACHE_KEY, self::THROTTLE_CACHE_GROUP );
			} elseif ( 'apcu' === $backend ) {
				$success = false;
				$state   = apcu_fetch( self::THROTTLE_CACHE_KEY, $success );
				if ( ! $success ) {
					$state = false;
				}
			} else {
				$state = get_option( self::THROTTLE_STATE_OPTION, false );
			}

			if ( ! is_array( $state ) ) {
				$state = array(
					'level'      => self::THROTTLE_LEVEL_NORMAL,
					'changed_at' => 0,
				);
			}

			self::$throttle_state_cache = $state;
			return $state;
		}

		/**
		 * Persist hysteresis state across requests.
		 *
		 * @param array<string,mixed> $decision
		 * @return void
		 */
		private static function persist_throttle_state( array $decision ) {
			$state = array(
				'level'      => $decision['level'],
				'changed_at' => (int) $decision['changed_at'],
			);
			$previous = self::$throttle_state_cache;
			$backend  = self::get_throttle_cache_backend();
			$ttl      = max( 60, self::get_throttle_thresholds()['level_min_dwell_seconds'] * 4 );

			if ( is_array( $previous ) && $previous === $state ) {
				if ( 'persistent_object_cache' === $backend ) {
					wp_cache_set( self::THROTTLE_CACHE_KEY, $state, self::THROTTLE_CACHE_GROUP, $ttl );
				} elseif ( 'apcu' === $backend ) {
					apcu_store( self::THROTTLE_CACHE_KEY, $state, $ttl );
				}
				return;
			}

			self::$throttle_state_cache = $state;

			if ( 'persistent_object_cache' === $backend ) {
				wp_cache_set( self::THROTTLE_CACHE_KEY, $state, self::THROTTLE_CACHE_GROUP, $ttl );
				return;
			}

			if ( 'apcu' === $backend ) {
				apcu_store( self::THROTTLE_CACHE_KEY, $state, $ttl );
				return;
			}

			if ( false === get_option( self::THROTTLE_STATE_OPTION, false ) ) {
				add_option( self::THROTTLE_STATE_OPTION, $state, '', false );
				return;
			}

			update_option( self::THROTTLE_STATE_OPTION, $state, false );
		}

		/**
		 * Probe available load signals.
		 *
		 * @return array<string,mixed>
		 */
		private static function collect_throttle_metrics() {
			$threads_running = self::probe_threads_running();
			$queue_depth     = self::probe_due_queue_depth();
			$mode            = 'none';

			if ( null !== $threads_running['value'] && null !== $queue_depth['value'] ) {
				$mode = 'mixed';
			} elseif ( null !== $threads_running['value'] ) {
				$mode = 'threads_running';
			} elseif ( null !== $queue_depth['value'] ) {
				$mode = 'queue_depth';
			}

			return array(
				'threads_running' => $threads_running['value'],
				'queue_depth'     => $queue_depth['value'],
				'probe_ms'        => array(
					'threads_running' => $threads_running['ms'],
					'queue_depth'     => $queue_depth['ms'],
				),
				'errors'          => array(
					'threads_running' => $threads_running['error'],
					'queue_depth'     => $queue_depth['error'],
				),
				'detector_mode'   => $mode,
			);
		}

		/**
		 * Probe MySQL Threads_running with direct mysqli to avoid polluting wpdb state.
		 *
		 * @return array{value:int|null,ms:int,error:string}
		 */
		private static function probe_threads_running() {
			$result = self::run_mysqli_query( "SHOW STATUS LIKE 'Threads_running'" );
			if ( empty( $result['rows'] ) || ! isset( $result['rows'][0]['Value'] ) ) {
				return array(
					'value' => null,
					'ms'    => $result['ms'],
					'error' => $result['error'],
				);
			}

			return array(
				'value' => (int) $result['rows'][0]['Value'],
				'ms'    => $result['ms'],
				'error' => '',
			);
		}

		/**
		 * Probe due queue depth from Action Scheduler's custom tables.
		 *
		 * @return array{value:int|null,ms:int,error:string}
		 */
		private static function probe_due_queue_depth() {
			global $wpdb;

			$table  = preg_replace( '/[^A-Za-z0-9_]/', '', $wpdb->prefix ) . 'actionscheduler_actions';
			$sql    = "SELECT COUNT(*) AS count FROM `{$table}` WHERE status = 'pending' AND scheduled_date_gmt <= UTC_TIMESTAMP()";
			$result = self::run_mysqli_query( $sql );

			if ( empty( $result['rows'] ) || ! isset( $result['rows'][0]['count'] ) ) {
				return array(
					'value' => null,
					'ms'    => $result['ms'],
					'error' => $result['error'],
				);
			}

			return array(
				'value' => (int) $result['rows'][0]['count'],
				'ms'    => $result['ms'],
				'error' => '',
			);
		}

		/**
		 * Execute a direct mysqli query and return rows plus timing.
		 *
		 * @param string $sql
		 * @return array{rows:array<int,array<string,string>>|null,ms:int,error:string}
		 */
		private static function run_mysqli_query( $sql ) {
			global $wpdb;

			if ( empty( $wpdb ) || empty( $wpdb->dbh ) || ! function_exists( 'mysqli_query' ) ) {
				return array(
					'rows'  => null,
					'ms'    => 0,
					'error' => 'mysqli_unavailable',
				);
			}

			$start  = microtime( true );
			$result = @mysqli_query( $wpdb->dbh, $sql );
			$ms     = (int) round( ( microtime( true ) - $start ) * 1000 );

			if ( false === $result ) {
				return array(
					'rows'  => null,
					'ms'    => $ms,
					'error' => function_exists( 'mysqli_error' ) ? (string) mysqli_error( $wpdb->dbh ) : 'mysqli_query_failed',
				);
			}

			$rows = array();
			while ( $row = mysqli_fetch_assoc( $result ) ) {
				$rows[] = $row;
			}
			mysqli_free_result( $result );

			return array(
				'rows'  => $rows,
				'ms'    => $ms,
				'error' => '',
			);
		}

		/**
		 * Evaluate the next load level with hysteresis and minimum dwell.
		 *
		 * @param array<string,mixed> $metrics
		 * @param array<string,int>   $thresholds
		 * @param string              $previous_level
		 * @param int                 $previous_at
		 * @return array{level:string,raw_level:string}
		 */
		private static function evaluate_throttle_level( array $metrics, array $thresholds, $previous_level, $previous_at ) {
			$threads_level = self::classify_metric_level(
				$metrics['threads_running'],
				$thresholds['threads_running_elevated'],
				$thresholds['threads_running_critical'],
				$thresholds['threads_running_elevated_exit'],
				$thresholds['threads_running_critical_exit'],
				$previous_level
			);
			$queue_level   = self::classify_metric_level(
				$metrics['queue_depth'],
				$thresholds['queue_depth_elevated'],
				$thresholds['queue_depth_critical'],
				$thresholds['queue_depth_elevated_exit'],
				$thresholds['queue_depth_critical_exit'],
				$previous_level
			);
			$raw_level     = self::pick_higher_level( $threads_level, $queue_level );

			if ( 'none' === $metrics['detector_mode'] ) {
				$raw_level = self::THROTTLE_LEVEL_ELEVATED;
			}

			if ( self::severity_for_level( $raw_level ) < self::severity_for_level( $previous_level ) ) {
				$dwell = max( 0, (int) $thresholds['level_min_dwell_seconds'] );
				if ( $previous_at > 0 && ( time() - $previous_at ) < $dwell ) {
					return array(
						'level'     => $previous_level,
						'raw_level' => $raw_level,
					);
				}
			}

			return array(
				'level'     => $raw_level,
				'raw_level' => $raw_level,
			);
		}

		/**
		 * Convert a single metric to a throttling level with hysteresis exits.
		 *
		 * @param int|null $value
		 * @param int      $elevated
		 * @param int      $critical
		 * @param int      $elevated_exit
		 * @param int      $critical_exit
		 * @param string   $previous_level
		 * @return string
		 */
		private static function classify_metric_level( $value, $elevated, $critical, $elevated_exit, $critical_exit, $previous_level ) {
			if ( null === $value ) {
				return self::THROTTLE_LEVEL_NORMAL;
			}

			if ( self::THROTTLE_LEVEL_CRITICAL === $previous_level ) {
				if ( $value >= $critical_exit ) {
					return self::THROTTLE_LEVEL_CRITICAL;
				}
				if ( $value >= $elevated ) {
					return self::THROTTLE_LEVEL_ELEVATED;
				}
				return self::THROTTLE_LEVEL_NORMAL;
			}

			if ( self::THROTTLE_LEVEL_ELEVATED === $previous_level ) {
				if ( $value >= $critical ) {
					return self::THROTTLE_LEVEL_CRITICAL;
				}
				if ( $value >= $elevated_exit ) {
					return self::THROTTLE_LEVEL_ELEVATED;
				}
				return self::THROTTLE_LEVEL_NORMAL;
			}

			if ( $value >= $critical ) {
				return self::THROTTLE_LEVEL_CRITICAL;
			}
			if ( $value >= $elevated ) {
				return self::THROTTLE_LEVEL_ELEVATED;
			}

			return self::THROTTLE_LEVEL_NORMAL;
		}

		/**
		 * Pick the higher-severity of two levels.
		 *
		 * @param string $left
		 * @param string $right
		 * @return string
		 */
		private static function pick_higher_level( $left, $right ) {
			return self::severity_for_level( $left ) >= self::severity_for_level( $right ) ? $left : $right;
		}

		/**
		 * Numeric severity for a throttle level.
		 *
		 * @param string $level
		 * @return int
		 */
		private static function severity_for_level( $level ) {
			if ( self::THROTTLE_LEVEL_CRITICAL === $level ) {
				return 2;
			}
			if ( self::THROTTLE_LEVEL_ELEVATED === $level ) {
				return 1;
			}
			return 0;
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
				'uri'             => isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '',
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
			if ( 0 === $limit_ms ) {
				return; // Unlimited contexts (WP-CLI, Action Scheduler).
			}

			static $last_dbh   = null;
			static $last_limit = null;

			// Skip the round-trip if both connection identity and limit are unchanged.
			if ( $last_dbh === $wpdb->dbh && $last_limit === $limit_ms ) {
				return;
			}

			// Suppress wpdb's own error reporting for the SET itself; if MySQL
			// rejects it (very old version), we don't want to break the request.
			$prev_suppress = $wpdb->suppress_errors( true );
			$wpdb->query( $wpdb->prepare( 'SET SESSION MAX_EXECUTION_TIME = %d', $limit_ms ) );
			$wpdb->suppress_errors( $prev_suppress );

			$last_dbh   = $wpdb->dbh;
			$last_limit = $limit_ms;
		}

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

			$payload = array(
				'event'      => 'query_killed',
				'context'    => self::detect_context(),
				'limit_ms'   => self::get_limit_ms(),
				'last_query' => self::truncate( (string) $wpdb->last_query, 500 ),
				'uri'        => isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '',
				'user_id'    => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
				'time'       => time(),
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

			if ( empty( $wpdb ) || ! defined( 'SAVEQUERIES' ) || ! SAVEQUERIES ) {
				return;
			}
			if ( empty( $wpdb->queries ) || ! is_array( $wpdb->queries ) ) {
				return;
			}

			$threshold_s = self::WARN_THRESHOLD_MS / 1000;
			$context     = self::detect_context();
			$uri         = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';

			foreach ( $wpdb->queries as $row ) {
				// $row = [ $query, $duration_seconds, $callstack, $start_microtime, ... ]
				if ( ! isset( $row[1] ) || $row[1] < $threshold_s ) {
					continue;
				}
				self::log(
					'warn',
					array(
						'event'       => 'slow_query',
						'context'     => $context,
						'duration_ms' => (int) ( $row[1] * 1000 ),
						'query'       => self::truncate( (string) $row[0], 500 ),
						'caller'      => isset( $row[2] ) ? self::truncate( (string) $row[2], 500 ) : '',
						'uri'         => $uri,
					)
				);
			}
		}

		/**
		 * Centralized log emitter. Prefers Hypercart_Logger if present (your
		 * existing file-based logger from the Performance Monitor plugin),
		 * falls back to error_log so this MU-plugin works standalone.
		 *
		 * @param string $level   'info' | 'warn' | 'error'
		 * @param array  $payload Structured fields.
		 */
		private static function log( $level, array $payload ) {
			if ( class_exists( 'Hypercart_Logger' ) ) {
				if ( 'error' === $level && method_exists( 'Hypercart_Logger', 'error' ) ) {
					Hypercart_Logger::error( 'query_guard', $payload );
					return;
				}
				if ( 'info' === $level && method_exists( 'Hypercart_Logger', 'info' ) ) {
					Hypercart_Logger::info( 'query_guard', $payload );
					return;
				}
				if ( method_exists( 'Hypercart_Logger', 'warn' ) ) {
					Hypercart_Logger::warn( 'query_guard', $payload );
					return;
				}
			}
			// Fallback: structured single-line JSON for grep-ability.
			error_log( '[hypercart_query_guard][' . $level . '] ' . wp_json_encode( $payload ) );
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
