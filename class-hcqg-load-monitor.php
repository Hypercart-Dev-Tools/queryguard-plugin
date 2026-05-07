<?php
/**
 * Load monitoring and hysteresis for Action Scheduler throttling.
 *
 * @package Hypercart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'HCQG_Load_Monitor' ) ) {

	final class HCQG_Load_Monitor {

		/**
		 * Load levels for Action Scheduler throttling.
		 */
		const LEVEL_NORMAL   = 'normal';
		const LEVEL_ELEVATED = 'elevated';
		const LEVEL_CRITICAL = 'critical';

		/**
		 * State storage for throttle hysteresis.
		 */
		const STATE_OPTION = 'hcqg_throttle_state';
		const CACHE_GROUP  = 'hypercart_query_guard';
		const CACHE_KEY    = 'throttle_state';

		/**
		 * Default throttle thresholds.
		 */
		const DEFAULT_THRESHOLDS = array(
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
		 * Cached persisted throttle state.
		 *
		 * @var array<string,mixed>|null
		 */
		private static $state_cache = null;

		/**
		 * Memoized throttle cache backend identifier for the current request.
		 *
		 * @var string|null
		 */
		private static $cache_backend = null;

		/**
		 * Default throttle thresholds, filterable for host tuning.
		 *
		 * @return array<string,int>
		 */
		public static function get_thresholds() {
			$thresholds = apply_filters( 'hypercart_query_guard_load_thresholds', self::DEFAULT_THRESHOLDS );
			if ( ! is_array( $thresholds ) ) {
				$thresholds = self::DEFAULT_THRESHOLDS;
			}

			$merged = array_merge( self::DEFAULT_THRESHOLDS, $thresholds );
			foreach ( $merged as $key => $value ) {
				$merged[ $key ] = max( 0, (int) $value );
			}

			return $merged;
		}

		/**
		 * Identify the best available cross-request state backend.
		 *
		 * @return string
		 */
		public static function get_cache_backend() {
			if ( null !== self::$cache_backend ) {
				return self::$cache_backend;
			}

			if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
				self::$cache_backend = 'persistent_object_cache';
			} elseif ( self::is_apcu_available() ) {
				self::$cache_backend = 'apcu';
			} else {
				self::$cache_backend = 'db_fallback';
			}

			return self::$cache_backend;
		}

		/**
		 * Read the last persisted throttle state.
		 *
		 * @return array<string,mixed>
		 */
		public static function read_state() {
			if ( null !== self::$state_cache ) {
				return self::$state_cache;
			}

			$backend = self::get_cache_backend();
			$state   = false;

			if ( 'persistent_object_cache' === $backend ) {
				$state = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );
			} elseif ( 'apcu' === $backend ) {
				$success = false;
				$state   = apcu_fetch( self::CACHE_GROUP . ':' . self::CACHE_KEY, $success );
				if ( ! $success ) {
					$state = false;
				}
			} else {
				$state = get_option( self::STATE_OPTION, false );
			}

			if ( ! is_array( $state ) ) {
				$state = array(
					'level'      => self::LEVEL_NORMAL,
					'changed_at' => 0,
				);
			}

			self::$state_cache = $state;
			return $state;
		}

		/**
		 * Persist hysteresis state across requests.
		 *
		 * @param array<string,mixed> $state
		 * @return void
		 */
		public static function persist_state( array $state ) {
			$next_state = array(
				'level'      => isset( $state['level'] ) ? (string) $state['level'] : self::LEVEL_NORMAL,
				'changed_at' => isset( $state['changed_at'] ) ? (int) $state['changed_at'] : 0,
			);
			$previous = self::$state_cache;
			$backend  = self::get_cache_backend();
			$ttl      = max( 60, self::get_thresholds()['level_min_dwell_seconds'] * 4 );

			if ( is_array( $previous ) && $previous === $next_state ) {
				if ( 'persistent_object_cache' === $backend ) {
					wp_cache_set( self::CACHE_KEY, $next_state, self::CACHE_GROUP, $ttl );
				} elseif ( 'apcu' === $backend ) {
					apcu_store( self::CACHE_GROUP . ':' . self::CACHE_KEY, $next_state, $ttl );
				}
				return;
			}

			self::$state_cache = $next_state;

			if ( 'persistent_object_cache' === $backend ) {
				wp_cache_set( self::CACHE_KEY, $next_state, self::CACHE_GROUP, $ttl );
				return;
			}

			if ( 'apcu' === $backend ) {
				apcu_store( self::CACHE_GROUP . ':' . self::CACHE_KEY, $next_state, $ttl );
				return;
			}

			if ( false === get_option( self::STATE_OPTION, false ) ) {
				add_option( self::STATE_OPTION, $next_state, '', false );
				return;
			}

			update_option( self::STATE_OPTION, $next_state, false );
		}

		/**
		 * Probe available load signals.
		 *
		 * @return array<string,mixed>
		 */
		public static function collect_metrics() {
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
		 * Evaluate the next load level with hysteresis and minimum dwell.
		 *
		 * @param array<string,mixed> $metrics
		 * @param array<string,int>   $thresholds
		 * @param string              $previous_level
		 * @param int                 $previous_at
		 * @return array{level:string,raw_level:string}
		 */
		public static function evaluate_level( array $metrics, array $thresholds, $previous_level, $previous_at ) {
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
				$raw_level = self::LEVEL_ELEVATED;
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

			// Some WordPress sites replace wpdb's native mysqli handle with a
			// different connection type via a db.php drop-in. Passing a non-mysqli
			// object/resource into mysqli_query() throws a fatal TypeError on PHP 8+.
			if ( ! ( $wpdb->dbh instanceof mysqli ) ) {
				return array(
					'rows'  => null,
					'ms'    => 0,
					'error' => 'not_mysqli_connection',
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
				return self::LEVEL_NORMAL;
			}

			if ( self::LEVEL_CRITICAL === $previous_level ) {
				if ( $value >= $critical_exit ) {
					return self::LEVEL_CRITICAL;
				}
				if ( $value >= $elevated ) {
					return self::LEVEL_ELEVATED;
				}
				return self::LEVEL_NORMAL;
			}

			if ( self::LEVEL_ELEVATED === $previous_level ) {
				if ( $value >= $critical ) {
					return self::LEVEL_CRITICAL;
				}
				if ( $value >= $elevated_exit ) {
					return self::LEVEL_ELEVATED;
				}
				return self::LEVEL_NORMAL;
			}

			if ( $value >= $critical ) {
				return self::LEVEL_CRITICAL;
			}
			if ( $value >= $elevated ) {
				return self::LEVEL_ELEVATED;
			}

			return self::LEVEL_NORMAL;
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
			if ( self::LEVEL_CRITICAL === $level ) {
				return 2;
			}
			if ( self::LEVEL_ELEVATED === $level ) {
				return 1;
			}
			return 0;
		}
	}
}
