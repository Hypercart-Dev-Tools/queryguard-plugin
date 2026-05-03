# Changelog

All notable changes to Hypercart Query Guard are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **Action Scheduler workers no longer get the wrong execution-time ceiling.** `detect_context()` previously relied on `did_action('action_scheduler_before_process_queue')`, which has not yet fired at `init` priority 1 when `apply_session_timeout()` runs. Both AS transports were misclassified:
  - The async-loopback path (`admin-ajax.php?action=as_async_request_queue_runner`) fell through to `admin_ajax` and got a 20s ceiling.
  - The WP-Cron path (`action_scheduler_run_queue`) fell through to `wp_cron` and got a 10s ceiling.

  The static memo in `apply_session_timeout()` then locked in the wrong tier for the rest of the request, so the eventual firing of `action_scheduler_before_process_queue` could not recover the intended unlimited ceiling. AS detection now checks `$_REQUEST['action']` against the real AS action names up front, so workers get the unlimited ceiling from the first SET SESSION.

- **Every killed query in a request is now logged, not just the last one.** `detect_and_log_kill()` previously ran only on `shutdown` and read `$wpdb->last_error`, which gets overwritten by each subsequent query. A request that triggered N kills produced exactly one log line — the final one. Capture is now incremental: a `query` filter callback inspects `$wpdb->last_error` before each subsequent `wpdb::query()` clears it via `flush()`, and a high-water mark on `$wpdb->num_queries` makes the capture idempotent so duplicate error strings (the NoFraud thundering-herd pattern) are still counted as distinct kills. Shutdown remains the fallback for the request's final query.

## [1.0.0]

Initial release.
