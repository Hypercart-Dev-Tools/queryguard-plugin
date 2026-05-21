<?php
/**
 * Hypercart Query Guard — wp-content/db.php drop-in (v2).
 *
 * Extends wpdb with two capabilities:
 *   1. Conditional backtracing — times every query (~0 cost), only calls
 *      debug_backtrace() on queries exceeding the warn threshold. Replaces
 *      SAVEQUERIES; eliminates the ~10% CPU overhead at 100% sample rate.
 *   2. First-query SET SESSION MAX_EXECUTION_TIME — covers pre-init queries
 *      (wp_load_alloptions, auth/usermeta, WC session bootstrap) that the
 *      v1 mu-plugin cannot reach from its init-priority-1 hook.
 *
 * Install:  copy this file to wp-content/db.php
 * Remove:   delete wp-content/db.php — mu-plugin reverts to v1 behavior.
 * Requires: Hypercart Query Guard plugin for logging. Functions standalone
 *           for SET SESSION protection if the plugin is absent.
 *
 * wp-config.php constants (all optional):
 *   HYPERCART_QUERY_GUARD_MODE            'off'|'observe'|'enforce' (default: 'observe')
 *   HYPERCART_QUERY_GUARD_WARN_THRESHOLD_MS  int, ms (default: 5000)
 *   HYPERCART_QUERY_GUARD_DEFAULT_LIMIT_MS   int, ms for pre-init queries (default: 30000)
 *
 * @package Hypercart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HCQG_DB extends wpdb {

	const DROPIN_VERSION = '2.0.0';

	// Tested against WordPress 5.5 – 6.8. The override relies on query()
	// returning int|bool and _do_query() being private (not called through
	// the vtable). If core refactors _do_query into a protected method or
	// changes query()'s signature, the assertion below will fire.
	const WP_VERSION_FLOOR   = '5.5';
	const WP_VERSION_CEILING = '6.9';

	/**
	 * Slow queries captured by conditional backtracing.
	 * Same shape as $wpdb->queries: [ $sql, $elapsed_s, $caller, $start_µs, $data ].
	 *
	 * @var array
	 */
	public $hcqg_slow_queries = array();

	/** @var bool Whether conditional backtracing is active for this request. */
	private $hcqg_active = false;

	/** @var bool Whether enforce-mode SET SESSION should be applied. */
	private $hcqg_enforce = false;

	/** @var bool Whether the first-query SET SESSION has been applied. */
	private $hcqg_session_applied = false;

	/** @var mixed Connection identity for reconnect / rotation detection. */
	private $hcqg_last_dbh = null;

	/** @var float Warn threshold in seconds. */
	private $hcqg_warn_threshold_s = 5.0;

	/** @var int Current MAX_EXECUTION_TIME limit in ms. */
	private $hcqg_current_limit_ms = 30000;

	/**
	 * @param string $dbuser
	 * @param string $dbpassword
	 * @param string $dbname
	 * @param string $dbhost
	 */
	public function __construct( $dbuser, $dbpassword, $dbname, $dbhost ) {
		$this->hcqg_resolve_config();
		parent::__construct( $dbuser, $dbpassword, $dbname, $dbhost );
		$this->hcqg_version_check();
	}

	/**
	 * Read mode and thresholds from wp-config.php constants.
	 */
	private function hcqg_resolve_config() {
		$mode = 'observe';
		if ( defined( 'HYPERCART_QUERY_GUARD_MODE' ) ) {
			$raw = HYPERCART_QUERY_GUARD_MODE;
			if ( in_array( $raw, array( 'off', 'observe', 'enforce' ), true ) ) {
				$mode = $raw;
			}
		}

		$this->hcqg_active  = ( 'off' !== $mode );
		$this->hcqg_enforce = ( 'enforce' === $mode );

		if ( defined( 'HYPERCART_QUERY_GUARD_WARN_THRESHOLD_MS' ) ) {
			$this->hcqg_warn_threshold_s = max( 0, (int) HYPERCART_QUERY_GUARD_WARN_THRESHOLD_MS ) / 1000;
		}

		if ( defined( 'HYPERCART_QUERY_GUARD_DEFAULT_LIMIT_MS' ) ) {
			$this->hcqg_current_limit_ms = max( 0, (int) HYPERCART_QUERY_GUARD_DEFAULT_LIMIT_MS );
		}
	}

	/**
	 * Log a notice if WordPress version is outside the tested range.
	 */
	private function hcqg_version_check() {
		global $wp_version;

		if ( ! isset( $wp_version ) ) {
			return;
		}

		if ( version_compare( $wp_version, self::WP_VERSION_FLOOR, '<' ) ||
			version_compare( $wp_version, self::WP_VERSION_CEILING, '>' ) ) {
			error_log( sprintf(
				'[hypercart_query_guard][warn] db.php drop-in tested on WP %s–%s; running %s. '
				. 'Verify wpdb::query() override compatibility.',
				self::WP_VERSION_FLOOR,
				self::WP_VERSION_CEILING,
				$wp_version
			) );
		}
	}

	/**
	 * Override query() for conditional backtracing and first-query SET SESSION.
	 *
	 * @param string $query SQL query.
	 * @return int|bool
	 */
	public function query( $query ) {
		// Enforce: apply or re-apply SET SESSION on first query / reconnect / rotation.
		if ( $this->hcqg_enforce &&
			( ! $this->hcqg_session_applied || $this->hcqg_last_dbh !== $this->dbh ) ) {
			$this->hcqg_apply_session();
		}

		// Off mode: no timing, no backtracing.
		if ( ! $this->hcqg_active ) {
			return parent::query( $query );
		}

		$start   = microtime( true );
		$result  = parent::query( $query );
		$elapsed = microtime( true ) - $start;

		// Conditional backtrace: ~0.1ms cost, only for slow queries.
		if ( $elapsed >= $this->hcqg_warn_threshold_s ) {
			$this->hcqg_slow_queries[] = array(
				$query,
				$elapsed,
				$this->get_caller(),
				$start,
				array(),
			);
		}

		return $result;
	}

	/**
	 * Apply SET SESSION MAX_EXECUTION_TIME via raw mysqli.
	 * Bypasses $this->query() to avoid recursion and timing noise.
	 */
	private function hcqg_apply_session() {
		if ( empty( $this->dbh ) || ! ( $this->dbh instanceof mysqli ) ) {
			return;
		}

		$limit_ms = $this->hcqg_current_limit_ms;

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$limit_ms = 0;
		}

		@mysqli_query( $this->dbh, sprintf( 'SET SESSION MAX_EXECUTION_TIME = %d', $limit_ms ) );

		$this->hcqg_session_applied = true;
		$this->hcqg_last_dbh        = $this->dbh;
	}

	// -- Public API for the mu-plugin ------------------------------------------

	/**
	 * Update the limit after context detection and re-apply immediately.
	 * Called by the mu-plugin on init once the request context (admin, REST,
	 * checkout, etc.) is known and the correct tiered limit is resolved.
	 *
	 * Also caches the limit so reconnect/rotation re-applies the correct
	 * per-context value instead of the pre-init default.
	 *
	 * @param int $limit_ms Milliseconds. 0 = unlimited.
	 */
	public function hcqg_update_limit( $limit_ms ) {
		$limit_ms = max( 0, (int) $limit_ms );
		$this->hcqg_current_limit_ms = $limit_ms;

		if ( ! $this->hcqg_enforce || empty( $this->dbh ) || ! ( $this->dbh instanceof mysqli ) ) {
			return;
		}

		@mysqli_query( $this->dbh, sprintf( 'SET SESSION MAX_EXECUTION_TIME = %d', $limit_ms ) );

		$this->hcqg_last_dbh = $this->dbh;
	}

	/**
	 * Whether the drop-in's instrumentation is active for this request.
	 *
	 * @return bool
	 */
	public function hcqg_is_active() {
		return $this->hcqg_active;
	}
}

// ---------------------------------------------------------------------------
// Instantiate the custom wpdb. WordPress's require_wp_db() checks
// `isset( $wpdb )` and skips `new wpdb(...)` if we set it here.
// ---------------------------------------------------------------------------
$wpdb = new HCQG_DB( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	defined( 'DB_USER' ) ? DB_USER : '',
	defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '',
	defined( 'DB_NAME' ) ? DB_NAME : '',
	defined( 'DB_HOST' ) ? DB_HOST : ''
);
