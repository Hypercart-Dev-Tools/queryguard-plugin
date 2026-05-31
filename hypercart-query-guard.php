<?php
/**
 * Plugin Name:       Hypercart Query Guard
 * Plugin URI:        https://hypercart.io
 * Description:       PHP-side circuit breaker that enforces MySQL MAX_EXECUTION_TIME on read queries to prevent runaway SELECTs from saturating the pod. Tiered limits per request context, observe-mode for safe rollout, automatic re-application on connection rotation, and admin-search timeout fallback.
 * Version:           1.1.0
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
		 * Bootstrap.
		 */
		public static function init() {
			$mode = self::get_mode();
			if ( self::MODE_OFF === $mode ) {
				return;
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
			if ( self::should_observe() ) {
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
		 * Decide whether to enable SAVEQUERIES-based observation for this request.
		 * In enforce mode we always observe (cheap, since SET SESSION is the real
		 * protection). In observe mode we sample to bound overhead.
		 *
		 * @return bool
		 */
		private static function should_observe() {
			$mode = self::get_mode();
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
		 * Threshold for classifying an IN-list as "large". Queries with an IN
		 * clause containing this many or more items are flagged in log payloads.
		 * Typical WordPress IN lists (get_posts with specific IDs, WC item rows)
		 * stay well under 50; anything above 200 is unusual enough to log.
		 */
		const LARGE_IN_LIST_THRESHOLD = 200;

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

		/**
		 * Centralized log emitter. Prefers Hypercart_Logger if present (your
		 * existing file-based logger from the Performance Monitor plugin),
		 * falls back to error_log so this MU-plugin works standalone.
		 *
		 * @param string $level   'warn' | 'error'
		 * @param array  $payload Structured fields.
		 */
		private static function log( $level, array $payload ) {
			/**
			 * Filter the structured log payload before emission. Use this hook to
			 * add custom fields, route to additional sinks, or intercept payloads
			 * in integration tests.
			 *
			 * Callbacks MUST return $payload. A callback that omits the return
			 * would yield null here; (array) null === [] which silently drops
			 * every field. We guard against that: if the filtered value is not a
			 * non-empty array, the original $payload is used as-is.
			 *
			 * @param array  $payload Log fields.
			 * @param string $level   'warn' | 'error'
			 */
			$filtered = apply_filters( 'hypercart_query_guard_log_payload', $payload, $level );
			if ( is_array( $filtered ) && ! empty( $filtered ) ) {
				$payload = $filtered;
			}

			if ( class_exists( 'Hypercart_Logger' ) ) {
				if ( 'error' === $level && method_exists( 'Hypercart_Logger', 'error' ) ) {
					Hypercart_Logger::error( 'query_guard', $payload );
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
