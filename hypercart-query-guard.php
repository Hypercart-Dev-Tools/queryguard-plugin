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
 * v2 drop-in:        Optional wp-content/db.php applies SET SESSION at
 *                    connection time and conditionally backtraces slow
 *                    queries. Without the drop-in, this MU-plugin falls
 *                    back to the v1 init-priority-1 behavior.
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
			'admin_ajax'       => 20000,
			'rest_api'         => 30000,
			'checkout'         => 60000,
			'wp_admin'         => 45000,
			'frontend'         => 30000,
		);

		/**
		 * Consequence severity tiers for timeout enforcement.
		 */
		const CONSEQUENCE_TIER_INVISIBLE     = 'invisible';
		const CONSEQUENCE_TIER_RETRY_SAFE    = 'retry_safe';
		const CONSEQUENCE_TIER_USER_VISIBLE  = 'user_visible';
		const CONSEQUENCE_TIER_TRANSACTIONAL = 'transactional';

		/**
		 * Canonical consequence-tier order.
		 */
		const CONSEQUENCE_TIERS = array(
			self::CONSEQUENCE_TIER_INVISIBLE,
			self::CONSEQUENCE_TIER_RETRY_SAFE,
			self::CONSEQUENCE_TIER_USER_VISIBLE,
			self::CONSEQUENCE_TIER_TRANSACTIONAL,
		);

		/**
		 * Default context -> consequence-tier mapping.
		 */
		const CONTEXT_CONSEQUENCE_TIERS = array(
			'wp_cli'           => self::CONSEQUENCE_TIER_TRANSACTIONAL,
			'action_scheduler' => self::CONSEQUENCE_TIER_RETRY_SAFE,
			'wp_cron'          => self::CONSEQUENCE_TIER_RETRY_SAFE,
			'admin_ajax'       => self::CONSEQUENCE_TIER_USER_VISIBLE,
			'rest_api'         => self::CONSEQUENCE_TIER_USER_VISIBLE,
			'checkout'         => self::CONSEQUENCE_TIER_TRANSACTIONAL,
			'wp_admin'         => self::CONSEQUENCE_TIER_USER_VISIBLE,
			'frontend'         => self::CONSEQUENCE_TIER_USER_VISIBLE,
		);

		/**
		 * Per-request timeout policy snapshot.
		 *
		 * `resolved_policy` is the most recent in-request context/tier/limit
		 * calculation. `applied_policy` is the policy actually applied via
		 * SET SESSION MAX_EXECUTION_TIME.
		 *
		 * @var array<string,mixed>
		 */
		private static $timeout_runtime = array();

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
			$mode          = self::get_mode();
			$throttle_mode = self::get_throttle_mode();

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
			$policy = self::resolve_timeout_policy();
			return (int) $policy['limit_ms'];
		}

		/**
		 * Resolve timeout policy for current request.
		 *
		 * @return array<string,mixed>
		 */
		private static function resolve_timeout_policy() {
			$context = self::detect_context();
			$limits  = self::LIMITS_MS;

			$limit = isset( $limits[ $context ] ) ? (int) $limits[ $context ] : 30000;

			$context_consequence_limits = self::get_context_consequence_limits_ms();
			$consequence_tier           = self::detect_consequence_tier( $context );
			if (
				isset( $context_consequence_limits[ $context ] ) &&
				is_array( $context_consequence_limits[ $context ] ) &&
				isset( $context_consequence_limits[ $context ][ $consequence_tier ] )
			) {
				$limit = (int) $context_consequence_limits[ $context ][ $consequence_tier ];
			}

			/**
			 * Filter the per-request execution-time ceiling.
			 *
			 * @param int    $limit_ms Milliseconds. 0 = unlimited.
			 * @param string $context  Detected context key.
			 * @param string $consequence_tier Consequence tier key.
			 */
			$limit = (int) apply_filters( 'hypercart_query_guard_limit_ms', $limit, $context, $consequence_tier );

			$policy = array(
				'context'          => $context,
				'consequence_tier' => $consequence_tier,
				'limit_ms'         => $limit,
			);

			self::$timeout_runtime['resolved_policy'] = $policy;

			return $policy;
		}

		/**
		 * Resolve timeout policy for logging.
		 *
		 * In enforce mode, logs should reflect the policy actually applied to
		 * MySQL with SET SESSION, not a late-request recomputation.
		 *
		 * @return array<string,mixed>
		 */
		private static function get_timeout_policy_for_logging() {
			if ( isset( self::$timeout_runtime['applied_policy'] ) && is_array( self::$timeout_runtime['applied_policy'] ) ) {
				return self::$timeout_runtime['applied_policy'];
			}

			return self::resolve_timeout_policy();
		}

		/**
		 * Build the default context->tier timeout matrix from LIMITS_MS.
		 *
		 * LIMITS_MS is the single source of truth for default ceilings.
		 *
		 * @return array<string,array<string,int>>
		 */
		private static function get_default_context_consequence_limits_ms() {
			$matrix = array();
			foreach ( self::LIMITS_MS as $context => $limit ) {
				$matrix[ $context ] = array();
				foreach ( self::CONSEQUENCE_TIERS as $tier ) {
					$matrix[ $context ][ $tier ] = (int) $limit;
				}
			}

			return $matrix;
		}

		/**
		 * Resolve context->consequence-tier timeout matrix.
		 *
		 * Filter contract for `hypercart_query_guard_context_consequence_limits_ms`:
		 *   - Context keys are fixed to self::LIMITS_MS keys.
		 *   - Tier keys are fixed to self::CONSEQUENCE_TIERS.
		 *   - Omitted context/tier entries inherit defaults.
		 *   - Values are clamped to int >= 0.
		 *
		 * @return array<string,array<string,int>>
		 */
		private static function get_context_consequence_limits_ms() {
			$defaults = self::get_default_context_consequence_limits_ms();
			$filtered = apply_filters( 'hypercart_query_guard_context_consequence_limits_ms', $defaults );
			if ( ! is_array( $filtered ) ) {
				$filtered = $defaults;
			}

			$matrix = array();
			foreach ( $defaults as $context => $default_tiers ) {
				$source = $default_tiers;
				if ( isset( $filtered[ $context ] ) && is_array( $filtered[ $context ] ) ) {
					$source = array_merge( $default_tiers, $filtered[ $context ] );
				}

				$matrix[ $context ] = array();
				foreach ( self::CONSEQUENCE_TIERS as $tier ) {
					$raw                         = isset( $source[ $tier ] ) ? $source[ $tier ] : $default_tiers[ $tier ];
					$matrix[ $context ][ $tier ] = max( 0, (int) $raw );
				}
			}

			return $matrix;
		}

		/**
		 * Resolve consequence tier for the current request context.
		 *
		 * @param string $context Detected request context.
		 * @return string
		 */
		private static function detect_consequence_tier( $context ) {
			$context = (string) $context;
			$tier    = isset( self::CONTEXT_CONSEQUENCE_TIERS[ $context ] )
				? self::CONTEXT_CONSEQUENCE_TIERS[ $context ]
				: self::CONSEQUENCE_TIER_USER_VISIBLE;

			/**
			 * Filter request consequence tier.
			 *
			 * Return one of self::CONSEQUENCE_TIERS.
			 * Unknown/non-string values are ignored.
			 *
			 * @param string $tier    Default tier.
			 * @param string $context Detected context key.
			 * @param string $uri     Request URI (sanitized, may be empty).
			 */
			$filtered = apply_filters(
				'hypercart_query_guard_consequence_tier',
				$tier,
				$context,
				isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''
			);

			if ( is_string( $filtered ) && in_array( $filtered, self::CONSEQUENCE_TIERS, true ) ) {
				return $filtered;
			}

			return $tier;
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

			$policy   = self::resolve_timeout_policy();
			$limit_ms = (int) $policy['limit_ms'];

			if ( self::dropin_active() ) {
				static $dropin_last_limit = null;
				if ( $dropin_last_limit === $limit_ms ) {
					self::$timeout_runtime['applied_policy'] = $policy;
					return;
				}
				$wpdb->hcqg_update_limit( $limit_ms );
				$dropin_last_limit = $limit_ms;
				self::$timeout_runtime['applied_policy'] = $policy;
				return;
			}

			if ( 0 === $limit_ms ) {
				return; // Unlimited contexts (WP-CLI, Action Scheduler).
			}

			// v1 fallback.
			static $last_dbh   = null;
			static $last_limit = null;

			if ( $last_dbh === $wpdb->dbh && $last_limit === $limit_ms ) {
				self::$timeout_runtime['applied_policy'] = $policy;
				return;
			}

			$prev_suppress = $wpdb->suppress_errors( true );
			$result        = $wpdb->query( $wpdb->prepare( 'SET SESSION MAX_EXECUTION_TIME = %d', $limit_ms ) );
			$wpdb->suppress_errors( $prev_suppress );

			if ( false === $result ) {
				unset( self::$timeout_runtime['applied_policy'] );
				return;
			}

			$last_dbh   = $wpdb->dbh;
			$last_limit = $limit_ms;
			self::$timeout_runtime['applied_policy'] = $policy;
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

			$policy  = self::get_timeout_policy_for_logging();
			$payload = array(
				'event'            => 'query_killed',
				'context'          => isset( $policy['context'] ) ? (string) $policy['context'] : self::detect_context(),
				'consequence_tier' => isset( $policy['consequence_tier'] ) ? (string) $policy['consequence_tier'] : self::CONSEQUENCE_TIER_USER_VISIBLE,
				'limit_ms'         => isset( $policy['limit_ms'] ) ? (int) $policy['limit_ms'] : self::get_limit_ms(),
				'last_query'       => self::truncate( (string) $wpdb->last_query, 500 ),
				'uri'              => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
				'user_id'          => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
				'time'             => time(),
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
			$policy      = self::get_timeout_policy_for_logging();
			$context     = isset( $policy['context'] ) ? (string) $policy['context'] : self::detect_context();
			$tier        = isset( $policy['consequence_tier'] ) ? (string) $policy['consequence_tier'] : self::CONSEQUENCE_TIER_USER_VISIBLE;
			$uri         = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

			foreach ( $queries as $row ) {
				// $row = [ $query, $duration_seconds, $callstack, $start_microtime, ... ]
				if ( ! isset( $row[1] ) || $row[1] < $threshold_s ) {
					continue;
				}
				self::log(
					'warn',
					array(
						'event'       => 'slow_query',
						'context'     => $context,
						'consequence_tier' => $tier,
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
