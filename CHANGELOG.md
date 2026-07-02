# Changelog

All notable changes to Hypercart Query Guard are documented here.

This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Every merged build carries a version number.

## [1.2.0] — 2026-07-02

### Added

- **Cart type diagnostic (`HYPERCART_CART_TYPE_DIAGNOSTIC`).** Opt-in tracing for the production cart/checkout fatal `TypeError: Unsupported operand types: float / string` in `WC_Discounts::sort_by_price()` (universal-child-theme issue #888). In WooCommerce 10.8.x only a non-numeric string `quantity` can raise this fatal — price is float-cast upstream — so quantity findings are the authoritative signal. Two new error-level events:
  - **`cart_type_corruption`** — snapshots cart item `quantity` / `price` / `discounted_price` at `woocommerce_before_calculate_totals` priority `PHP_INT_MIN` and re-checks at `PHP_INT_MAX`. Each non-numeric value is reported with a type-safe rendering (objects/arrays never string-cast), origin attribution (`upstream` = bad before the hook ran, `hook_callback` = corrupted by a hook callback, `added_during_hook` = item added mid-hook, e.g. BOGO free gifts), applied coupon codes, user id, cart item count, request context, and callback lists for the six hooks able to write cart item values (including `woocommerce_get_cart_item_from_session` and the product price filters).
  - **`cart_fatal_captured`** — a `register_shutdown_function` catcher that matches the TypeError itself and dumps per-item quantity/price types from the in-memory cart. This is the guaranteed capture: `WC_Cart::apply_coupon()` validates via `new WC_Discounts( WC()->cart )` *before* any totals calculation, a path that never fires the instrumented hook.
  - Safety: every diagnostic entry point swallows `Throwable` — the tracer can never take down the cart it observes. Log volume is capped at 5 corruption events per PHP process (`CART_DIAG_MAX_LOGS`) with signature de-duplication across repeat hook firings, so multi-cart Action Scheduler processes can still report several distinct findings without flooding.
  - Gating: the wp-config constant plus a `hypercart_cart_type_diagnostic_enabled` filter override (register from wp-config or an earlier-loading mu-plugin).

- **`tests/CartDiagTest.php`.** 19 tests covering classification and attribution, malformed value shapes (object/array/missing/non-array rows, throwing price reads), the log budget and de-duplication, hook callback enumeration (named/array/closure/invokable, pre-4.7 plain-array rows), and shutdown fatal capture. Integration gaps (real `WC_Cart`, live hook dispatch, actual shutdown sequence) are exercised manually on the Local site.

## [1.1.0] — 2026-05-30

### Changed

- **`admin_ajax` execution-time ceiling tightened from 20 s to 10 s.** `admin-ajax.php` is the primary vector for runaway read queries (WC order-note loading, Facebook background sync, NoFraud). Halving the ceiling halves the per-execution DB exposure when concurrent workers pile up. Legitimate heavyweight reads that need > 10 s should use the REST API (30 s ceiling) or `wp-admin` (45 s ceiling). The `checkout` context (60 s) is unaffected — it is detected before `admin_ajax`. Operators can relax individual endpoints via the `hypercart_query_guard_limit_ms` filter.

### Added

- **SQL shape classifier (`classify_sql`).** A new `public static` method inspects a SQL string cheaply (plain string operations + one anchored regex for `IN (`) and returns six classification fields: `table_hint`, `is_comment_query`, `has_large_in_list`, `estimated_in_list_size`, `is_probable_woocommerce`, and `is_probable_order_note_query`. Only `SELECT` statements are classified; writes return all-default values. The classifier's large-IN threshold is 200 items (constant `LARGE_IN_LIST_THRESHOLD`).

- **`is_admin_ajax_request` helper.** Public static method — takes a URI string and returns `true` when it routes through `admin-ajax.php`. Used for logging; no behaviour change.

- **Seven new structured fields on `slow_query` and `query_killed` log events.** Both event types now carry `is_admin_ajax`, `table_hint`, `is_comment_query`, `has_large_in_list`, `estimated_in_list_size`, `is_probable_woocommerce`, and `is_probable_order_note_query`. These fields directly address the May 2026 production incident where a WooCommerce order-note query (`wp_comments … comment_ID IN (… 10,639 IDs …)`) running via `admin-ajax.php` consumed a large share of DB time and caused repeated `MySQL server has gone away` errors.

- **`hypercart_query_guard_log_payload` filter.** Fires inside `log()` before emission. Allows callers to add custom fields, route to additional sinks, or inspect payloads in integration tests.

- **PHPUnit test suite.** `phpunit.xml`, `tests/bootstrap.php` (WordPress function stubs), `tests/SqlClassifierTest.php` (22 tests covering classifier edge cases), and `tests/PayloadFieldsTest.php` (13 tests verifying both `query_killed` and `slow_query` payload shapes). 35 tests, 117 assertions.

### Added

- **Phase 1 Action Scheduler throttle modes.** Query Guard now supports a separate `HYPERCART_QUERY_GUARD_THROTTLE_MODE` with `off`, `test_observe`, `observe`, and `enforce` modes. The throttle hooks Action Scheduler's web queue runner and can reduce batch size, time limit, and concurrent batches under load without changing the existing MySQL query-kill rollout model.

- **Managed-host-safe load detection and capability logging.** The throttle probes `Threads_running` opportunistically, falls back to due queue depth from `actionscheduler_actions`, logs probe latency and signal availability, and records `load_level_transition`, `as_throttle_capability_test`, `as_throttle_observed`, and `as_throttle_applied` events for rollout analysis.

- **Cross-request hysteresis state for throttle decisions.** The throttle persists the last load level and transition timestamp using the best available backend: persistent object cache first, APCu second, and a low-write WordPress option fallback last. This keeps `test_observe`/`observe`/`enforce` decisions from flapping between requests on hosts without Redis or Memcached.

- **Wave A class extraction.** Load probing, hysteresis, and cross-request state persistence now live in `class-hcqg-load-monitor.php`, and the default per-hook priority map for future per-action throttling now lives in `class-hcqg-priority-registry.php`. The priority registry ships inert until Wave B.

- **Priority registry filter contract is now strict.** The tier list is fixed (`critical`, `high`, `normal`, `deferrable`) and always evaluated in that order; `hypercart_query_guard_priority_registry` filters can override or clear a tier's patterns but cannot add new tiers or change ordering, and unknown tier keys are dropped. The `hypercart_query_guard_action_priority` filter validates its return against the canonical tier list and falls back to the registry-resolved tier on an unknown or non-string return, so a buggy filter cannot leak an unrecognized tier into Wave B's throttle decisions.

- **PHPUnit suite for the extracted classes.** `tests/` adds 34 unit tests covering `HCQG_Priority_Registry` (wildcard/anchored/exact pattern matching, tier order, override/clear/inherit semantics, filter validation) and `HCQG_Load_Monitor` (threshold filter override + clamp, dwell, hysteresis exits in both directions, mixed-metric severity pick, "no detector" failsafe, and round-trip persistence across both `db_fallback` and `persistent_object_cache` backends). The bootstrap stubs the small set of WP functions the classes touch, so the suite has no WordPress runtime dependency and runs from `vendor/bin/phpunit` after `composer install`.

- **Initial Wave B per-action throttling.** Query Guard now resolves Action Scheduler hook names through the priority registry and can defer individual actions from `action_scheduler_before_execute` based on priority tier × load level, with filterable delay defaults and structured `as_action_deferred` logging.

- **Wave B safety hardening.** The per-action throttle now: (1) only registers `before_execute` in `observe`/`enforce` modes — `test_observe` stays a true capability probe with no per-action DB cost; (2) refuses to defer recurring actions, since cancelling a recurring instance breaks AS's recurrence chain and re-rooting it at `now+delay` silently shifts the cadence (and for cron schedules can drop a tick entirely); (3) caps deferrals per (hook, args, group) at 5 by default — filterable via `hypercart_query_guard_max_defer_count` — so a `deferrable`-tier action under sustained critical load eventually runs instead of starving; (4) guards `ActionScheduler_Action::get_priority()` / `set_priority()` with `method_exists` so sites on AS < 3.7 (older bundled WooCommerce) no longer fatal; (5) logs `as_action_defer_failed` on the `save_action()`-returns-zero path, not just on exceptions; (6) dedupes observe-mode `as_action_deferred` records by (hook, level) per request to bound log volume during sustained load; and (7) emits a new `as_action_deferral_skipped` event when a defer is suppressed by the count cap or by recurring-schedule policy.

- **Per-action throttle PHPUnit coverage.** New tests cover the delay-matrix filter contract (clamping, override-only-existing-cells semantics, unknown-level/tier rejection, non-array filter return), `get_action_delay_seconds` boundary behavior (normal-level returns 0, missing tier returns 0), and the defer-count cap helpers (increment / TTL behavior via the bootstrap's wp_cache stub).

- **End-to-end logger compatibility regression coverage.** The PHPUnit suite now includes a subprocess fixture that boots Query Guard against a string-typed `Hypercart_Logger` stub and exercises `Hypercart_Query_Guard::log()` end-to-end, so regressions in the string-only logger dispatch path no longer hide behind the default array-capable bootstrap stub.

- **Wave C Mutex Guard (Phase 1).** A new `HCQG_Mutex_Guard` subsystem coalesces concurrent invocations of the same operation across all PHP workers and (when the database is shared) all hosts — addresses thundering-herd patterns where the *same* expensive operation gets started N times in parallel, which throttling alone can't fix. Four static primitives plus a convenience wrapper: `acquire_lock( $key, $ttl = 60 )` returns a holder nonce or `false`, `release_lock( $key, $nonce )` is a CAS-conditional delete on nonce match, `peek_lock( $key )` returns the live row for monitoring (TOCTOU; do not branch control flow on it), `force_release( $key, $reason )` is admin/CLI recovery with structured logging, and `with_lock( $key, $ttl, $work )` wraps acquire/release around a callable with a `finally` so the lock can't leak. Storage is `wp_options` with `autoload = 'no'`; acquire is atomic via `INSERT ... ON DUPLICATE KEY UPDATE` (raw `$wpdb`, never `add_option`/`update_option` because they're TOCTOU); time anchoring uses PHP's `time()` as a SQL parameter, never `UNIX_TIMESTAMP()`, so web/DB clock skew on managed hosts can't drift the effective TTL; lock value is the delimited `expires_at|nonce` (chosen over JSON for portability across MySQL/MariaDB versions); `option_name` is `'hcqg_mutex_' . md5($key)` so caller keys can be arbitrary length. New events: `mutex_held`, `mutex_release_skipped`, `mutex_force_released`. PHPUnit coverage: 21 tests covering option-name encoding, lock-value round-trips, the four return paths of the atomic acquire (`rows_affected` 0/1/2), nonce CAS predicates, expired-row masking, force-release logging, and `with_lock` exception handling. The actual MySQL semantics of the `ON DUPLICATE KEY UPDATE` are validated through staging integration, not unit tests; the gap is explicit. See [ARCHITECTURE.md](ARCHITECTURE.md) for the design and [README.md](README.md) for the API + caller patterns.

### Fixed

- **Structured logging now works with both legacy and string-only Hypercart_Logger APIs.** `Hypercart_Query_Guard` and `HCQG_Mutex_Guard` now inspect the logger method signature before emitting records: array-capable logger methods still receive the structured payload, while string-only methods receive JSON-serialized payloads. This prevents the Action Scheduler admin screen from fatalling when the installed `Hypercart_Logger` typehints its message argument as `string`, while preserving compatibility with the existing array-based test harness and drop-in logging assertions.

- **Throttle probes now fail closed on non-MySQL db drop-ins instead of fatalling on PHP 8+.** `run_mysqli_query()` previously assumed `$wpdb->dbh` was always a native `mysqli` handle when `mysqli_query()` existed. On sites using a custom `db.php` drop-in with a different connection type (for example SQLite or custom routing layers), passing that handle into `mysqli_query()` could throw a fatal `TypeError` on PHP 8+. The probe now verifies `instanceof mysqli` before calling any `mysqli_*` API and degrades to a logged probe error when the connection is not native MySQLi.

- **Action Scheduler workers no longer get the wrong execution-time ceiling.** `detect_context()` previously relied on `did_action('action_scheduler_before_process_queue')`, which has not yet fired at `init` priority 1 when `apply_session_timeout()` runs. Both AS transports were misclassified:
  - The async-loopback path (`admin-ajax.php?action=as_async_request_queue_runner`) fell through to `admin_ajax` and got a 20s ceiling.
  - The WP-Cron path (`action_scheduler_run_queue`) fell through to `wp_cron` and got a 10s ceiling.

  The static memo in `apply_session_timeout()` then locked in the wrong tier for the rest of the request, so the eventual firing of `action_scheduler_before_process_queue` could not recover the intended unlimited ceiling. AS detection now checks `$_REQUEST['action']` against the real AS action names up front, so workers get the unlimited ceiling from the first SET SESSION.

- **Every killed query in a request is now logged, not just the last one.** `detect_and_log_kill()` previously ran only on `shutdown` and read `$wpdb->last_error`, which gets overwritten by each subsequent query. A request that triggered N kills produced exactly one log line — the final one. Capture is now incremental: a `query` filter callback inspects `$wpdb->last_error` before each subsequent `wpdb::query()` clears it via `flush()`, and a high-water mark on `$wpdb->num_queries` makes the capture idempotent so duplicate error strings (the NoFraud thundering-herd pattern) are still counted as distinct kills. Shutdown remains the fallback for the request's final query.

## [1.0.0] — 2026-05-01

Initial release.
