# Changelog

All notable changes to Hypercart Query Guard are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Phase 1 Action Scheduler throttle modes.** Query Guard now supports a separate `HYPERCART_QUERY_GUARD_THROTTLE_MODE` with `off`, `test_observe`, `observe`, and `enforce` modes. The throttle hooks Action Scheduler's web queue runner and can reduce batch size, time limit, and concurrent batches under load without changing the existing MySQL query-kill rollout model.

- **Managed-host-safe load detection and capability logging.** The throttle probes `Threads_running` opportunistically, falls back to due queue depth from `actionscheduler_actions`, logs probe latency and signal availability, and records `load_level_transition`, `as_throttle_capability_test`, `as_throttle_observed`, and `as_throttle_applied` events for rollout analysis.

- **Cross-request hysteresis state for throttle decisions.** The throttle persists the last load level and transition timestamp using the best available backend: persistent object cache first, APCu second, and a low-write WordPress option fallback last. This keeps `test_observe`/`observe`/`enforce` decisions from flapping between requests on hosts without Redis or Memcached.

- **Wave A class extraction.** Load probing, hysteresis, and cross-request state persistence now live in `class-hcqg-load-monitor.php`, and the default per-hook priority map for future per-action throttling now lives in `class-hcqg-priority-registry.php`. The priority registry ships inert until Wave B.

- **Priority registry filter contract is now strict.** The tier list is fixed (`critical`, `high`, `normal`, `deferrable`) and always evaluated in that order; `hypercart_query_guard_priority_registry` filters can override or clear a tier's patterns but cannot add new tiers or change ordering, and unknown tier keys are dropped. The `hypercart_query_guard_action_priority` filter validates its return against the canonical tier list and falls back to the registry-resolved tier on an unknown or non-string return, so a buggy filter cannot leak an unrecognized tier into Wave B's throttle decisions.

- **PHPUnit suite for the extracted classes.** `tests/` adds 34 unit tests covering `HCQG_Priority_Registry` (wildcard/anchored/exact pattern matching, tier order, override/clear/inherit semantics, filter validation) and `HCQG_Load_Monitor` (threshold filter override + clamp, dwell, hysteresis exits in both directions, mixed-metric severity pick, "no detector" failsafe, and round-trip persistence across both `db_fallback` and `persistent_object_cache` backends). The bootstrap stubs the small set of WP functions the classes touch, so the suite has no WordPress runtime dependency and runs from `vendor/bin/phpunit` after `composer install`.

### Fixed

- **Throttle probes now fail closed on non-MySQL db drop-ins instead of fatalling on PHP 8+.** `run_mysqli_query()` previously assumed `$wpdb->dbh` was always a native `mysqli` handle when `mysqli_query()` existed. On sites using a custom `db.php` drop-in with a different connection type (for example SQLite or custom routing layers), passing that handle into `mysqli_query()` could throw a fatal `TypeError` on PHP 8+. The probe now verifies `instanceof mysqli` before calling any `mysqli_*` API and degrades to a logged probe error when the connection is not native MySQLi.

- **Action Scheduler workers no longer get the wrong execution-time ceiling.** `detect_context()` previously relied on `did_action('action_scheduler_before_process_queue')`, which has not yet fired at `init` priority 1 when `apply_session_timeout()` runs. Both AS transports were misclassified:
  - The async-loopback path (`admin-ajax.php?action=as_async_request_queue_runner`) fell through to `admin_ajax` and got a 20s ceiling.
  - The WP-Cron path (`action_scheduler_run_queue`) fell through to `wp_cron` and got a 10s ceiling.

  The static memo in `apply_session_timeout()` then locked in the wrong tier for the rest of the request, so the eventual firing of `action_scheduler_before_process_queue` could not recover the intended unlimited ceiling. AS detection now checks `$_REQUEST['action']` against the real AS action names up front, so workers get the unlimited ceiling from the first SET SESSION.

- **Every killed query in a request is now logged, not just the last one.** `detect_and_log_kill()` previously ran only on `shutdown` and read `$wpdb->last_error`, which gets overwritten by each subsequent query. A request that triggered N kills produced exactly one log line — the final one. Capture is now incremental: a `query` filter callback inspects `$wpdb->last_error` before each subsequent `wpdb::query()` clears it via `flush()`, and a high-water mark on `$wpdb->num_queries` makes the capture idempotent so duplicate error strings (the NoFraud thundering-herd pattern) are still counted as distinct kills. Shutdown remains the fallback for the request's final query.

## [1.0.0]

Initial release.
