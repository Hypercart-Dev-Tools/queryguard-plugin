# Changelog

All notable changes to Hypercart Query Guard are documented here.

This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Every merged build carries a version number.

## [1.1.0] — 2026-05-30

### Changed

- **`admin_ajax` execution-time ceiling tightened from 20 s to 10 s.** `admin-ajax.php` is the primary vector for runaway read queries (WC order-note loading, Facebook background sync, NoFraud). Halving the ceiling halves the per-execution DB exposure when concurrent workers pile up. Legitimate heavyweight reads that need > 10 s should use the REST API (30 s ceiling) or `wp-admin` (45 s ceiling). The `checkout` context (60 s) is unaffected — it is detected before `admin_ajax`. Operators can relax individual endpoints via the `hypercart_query_guard_limit_ms` filter.

### Added

- **SQL shape classifier (`classify_sql`).** A new `public static` method inspects a SQL string cheaply (plain string operations + one anchored regex for `IN (`) and returns six classification fields: `table_hint`, `is_comment_query`, `has_large_in_list`, `estimated_in_list_size`, `is_probable_woocommerce`, and `is_probable_order_note_query`. Only `SELECT` statements are classified; writes return all-default values. The classifier's large-IN threshold is 200 items (constant `LARGE_IN_LIST_THRESHOLD`).

- **`is_admin_ajax_request` helper.** Public static method — takes a URI string and returns `true` when it routes through `admin-ajax.php`. Used for logging; no behaviour change.

- **Seven new structured fields on `slow_query` and `query_killed` log events.** Both event types now carry `is_admin_ajax`, `table_hint`, `is_comment_query`, `has_large_in_list`, `estimated_in_list_size`, `is_probable_woocommerce`, and `is_probable_order_note_query`. These fields directly address the May 2026 production incident where a WooCommerce order-note query (`wp_comments … comment_ID IN (… 10,639 IDs …)`) running via `admin-ajax.php` consumed a large share of DB time and caused repeated `MySQL server has gone away` errors.

- **`hypercart_query_guard_log_payload` filter.** Fires inside `log()` before emission. Allows callers to add custom fields, route to additional sinks, or inspect payloads in integration tests.

- **PHPUnit test suite.** `phpunit.xml`, `tests/bootstrap.php` (WordPress function stubs), `tests/SqlClassifierTest.php` (22 tests covering classifier edge cases), and `tests/PayloadFieldsTest.php` (13 tests verifying both `query_killed` and `slow_query` payload shapes). 35 tests, 117 assertions.

### Fixed

- **Action Scheduler workers no longer get the wrong execution-time ceiling.** `detect_context()` previously relied on `did_action('action_scheduler_before_process_queue')`, which has not yet fired at `init` priority 1 when `apply_session_timeout()` runs. Both AS transports were misclassified:
  - The async-loopback path (`admin-ajax.php?action=as_async_request_queue_runner`) fell through to `admin_ajax` and got a 20s ceiling.
  - The WP-Cron path (`action_scheduler_run_queue`) fell through to `wp_cron` and got a 10s ceiling.

  The static memo in `apply_session_timeout()` then locked in the wrong tier for the rest of the request, so the eventual firing of `action_scheduler_before_process_queue` could not recover the intended unlimited ceiling. AS detection now checks `$_REQUEST['action']` against the real AS action names up front, so workers get the unlimited ceiling from the first SET SESSION.

- **Every killed query in a request is now logged, not just the last one.** `detect_and_log_kill()` previously ran only on `shutdown` and read `$wpdb->last_error`, which gets overwritten by each subsequent query. A request that triggered N kills produced exactly one log line — the final one. Capture is now incremental: a `query` filter callback inspects `$wpdb->last_error` before each subsequent `wpdb::query()` clears it via `flush()`, and a high-water mark on `$wpdb->num_queries` makes the capture idempotent so duplicate error strings (the NoFraud thundering-herd pattern) are still counted as distinct kills. Shutdown remains the fallback for the request's final query.

## [1.0.0] — 2026-05-01

Initial release.
