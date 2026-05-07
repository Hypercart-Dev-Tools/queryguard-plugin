# Code Review: ce853ba..e27825c

**Scope**: PRs #24–#26 + feat/mutex-guard branch
**Commits**: 14 commits, 21 files, +3,170 / -581 lines
**Waves covered**: A (Load Monitor extraction), B (Per-action throttling), C (Mutex Guard Phase 1)
**Date reviewed**: 2026-05-07

---

## Positive Highlights

- **Atomic SQL in Mutex Guard** — `INSERT ... ON DUPLICATE KEY UPDATE` with `IF()` conditional is the correct pattern. CAS release via nonce comparison prevents TTL-overrun races.
- **Time anchoring** — All SQL uses PHP `time()`, never MySQL `UNIX_TIMESTAMP()`, avoiding clock skew on managed hosts.
- **Hysteresis model** — Dwell-time prevents flapping between load levels. Well-designed with proper edge case handling.
- **Safety downgrade** — Enforce mode auto-downgrades to observe when persistent cache is unavailable. Good safety rail.
- **Recurring schedule exemption** — Shows deep understanding of Action Scheduler recurrence chains. Well-documented rationale.
- **Filter contracts** — `get_action_delay_matrix()` docblock and normalization are exemplary. Clear specification of what filters can and cannot do.
- **Class-level docblocks** — Mutex Guard explains three non-obvious design decisions (raw SQL, time anchoring, no memoization) so future maintainers won't "fix" them.
- **Defensive `parse_value()`** — Checks for delimiter presence, part count, positive expiry, and non-empty holder. Prevents garbage data from being interpreted as a valid lock.
- **`finally` block in `with_lock()`** — Correctly handles exceptions without leaking locks.
- **Log deduplication** — Observe-mode dedup per request prevents log flooding during sustained load.
- **Test coverage for primitives** — 70 total tests across 4 test classes. Solid coverage of core logic: evaluate_level, priority matching, delay matrix, defer counting, and all mutex primitives.

---

## HIGH — Bugs or significant risks

### H1. APCu key collision (real bug)
- **Wave**: A
- **File**: `class-hcqg-load-monitor.php:117`
- **Issue**: `apcu_fetch('throttle_state')` uses no prefix. On shared hosting with shared APCu, multiple WordPress sites will read/write each other's throttle state.
- **Fix**: Prefix with `self::CACHE_GROUP . ':'`.

### H2. cancel/unclaim ordering inverted
- **Wave**: B
- **File**: `hypercart-query-guard.php:641-642`
- **Issue**: `cancel_action()` then `unclaim_action()` risks orphaned claim. If AS's `cancel_action()` already clears the claim (version-dependent), the `unclaim` is redundant; if not, the order should be reversed.
- **Fix**: Verify AS version behavior. Either reverse the order or remove the redundant call.

### H3. Unsanitized `$_SERVER['REQUEST_URI']` in log payloads
- **Wave**: B + C
- **Files**: `hypercart-query-guard.php:391,747,944` and `class-hcqg-mutex-guard.php:344`
- **Issue**: Raw user input flows into logs. `wp_json_encode` escapes for JSON, but if logs render in HTML admin UI, this is an XSS vector via log injection.
- **Fix**: Apply `esc_url_raw()` or `sanitize_text_field()` before storing in log payloads.

### H4. SQL interpolation of VALUE_DELIMITER
- **Wave**: C
- **File**: `class-hcqg-mutex-guard.php:94,137`
- **Issue**: The pipe `|` constant is concatenated directly into prepared SQL, bypassing `wpdb::prepare()`. Safe today (hardcoded const), but the pattern is fragile. If anyone changes `VALUE_DELIMITER` to include a single quote or SQL metacharacter, this becomes an injection.
- **Fix**: Add a defensive comment on the constant declaring it as a SQL-interpolated value that must remain a safe single character, or add an assertion.

### H5. Zero test coverage for collect_metrics / probes / run_mysqli_query
- **Wave**: A
- **Files**: `class-hcqg-load-monitor.php:185-378` (untested), `tests/LoadMonitorTest.php` (gap)
- **Issue**: The entire MySQL metrics subsystem that drives all throttle decisions is untested. `probe_threads_running()`, `probe_due_queue_depth()`, and `run_mysqli_query()` interact with `mysqli` directly. `collect_metrics()` is public and could be tested with a mock `$wpdb` that has a known `dbh`.
- **Fix**: Add integration-level tests or at minimum test `collect_metrics()` with a stubbed `$wpdb->dbh`.

### H6. Zero test coverage for integration-level deferral functions
- **Wave**: B
- **Files**: `hypercart-query-guard.php`, `tests/ActionThrottleTest.php` (gap)
- **Issue**: `get_action_throttle_decision()`, `maybe_defer_action_before_execute()`, `defer_action()`, `build_deferred_action_clone()` are all untested. These are the core integration surfaces of Wave B. Testing requires stubbing `ActionScheduler_Store`, `ActionScheduler_Action`, and `ActionScheduler_SimpleSchedule`.
- **Fix**: Add AS stubs to bootstrap and write integration tests for the deferral flow.

### H7. `wp_cache_set` stub drops TTL parameter
- **Wave**: Infra
- **File**: `tests/bootstrap.php:163`
- **Issue**: Production code passes TTL as 4th arg in `class-hcqg-load-monitor.php:153,163` and `hypercart-query-guard.php:717`. Stub silently ignores it. Future tests can't verify TTL behavior and could give false confidence.
- **Fix**: Add `$expire = 0` parameter to stub signature. Optionally capture it so tests can assert on TTL values.

---

## MEDIUM — Design improvements, moderate risk

### M1. CRC32 collision risk in defer_count_key
- **Wave**: B
- **File**: `hypercart-query-guard.php:706`
- **Issue**: CRC32 is a 32-bit hash. With thousands of distinct action signatures, birthday paradox gives meaningful collision probability. A collision means two different (hook, args, group) tuples share a defer counter, causing premature "max_defers_reached" for the wrong action.
- **Fix**: Swap `hash('crc32', ...)` to `md5(...)`. Negligible performance difference, dramatically larger collision space.

### M2. Non-atomic increment_defer_count
- **Wave**: B
- **File**: `hypercart-query-guard.php:714-718`
- **Issue**: Read-modify-write without atomicity. Two concurrent AS runners can both read count=2, both write count=3, losing an increment. Code acknowledges this ("best-effort, not a hard guarantee").
- **Fix**: Use `wp_cache_incr()` with `wp_cache_add()` as fallback for initialization. Atomic on persistent caches at no extra cost.

### M3. get_throttle_policy doesn't validate filter output structure
- **Wave**: B
- **File**: `hypercart-query-guard.php:474-479`
- **Issue**: Only checks `is_array()`. Unlike `get_action_delay_matrix()` which does full structural normalization. A filter returning `['elevated' => 'oops']` will cause a type error when `apply_throttle_value()` tries `(int) $decision['policy'][$level][$field]` on a non-array.
- **Fix**: Apply the same normalization pattern used in `get_action_delay_matrix()` — iterate known levels and fields, clamp to positive integers, fall back to defaults.

### M4. mt_rand fallback in generate_nonce is not cryptographically secure
- **Wave**: C
- **File**: `class-hcqg-mutex-guard.php:299-309`
- **Issue**: If `random_bytes` fails (essentially never on PHP 7+), falls back to predictable `mt_rand()`. An attacker who can predict the nonce could release someone else's lock.
- **Fix**: Use `openssl_random_pseudo_bytes()` as fallback, or let the exception propagate (fail-safe: failing to acquire is safer than acquiring with a predictable nonce).

### M5. No $wpdb->last_error checking after query()
- **Wave**: C
- **File**: `class-hcqg-mutex-guard.php:103,219`
- **Issue**: After `$wpdb->query($sql)`, only `rows_affected` is checked. DB failures (table missing, connection dropped, disk full) return `rows_affected = 0`, same as "lock held by someone else." The `mutex_held` log event fires, misleading operators into thinking there's contention when the DB is down.
- **Fix**: Check `$wpdb->last_error` after the query and log a distinct `mutex_acquire_error` event at `error` level.

### M6. get_thresholds allows filter to inject unknown keys
- **Wave**: A
- **File**: `class-hcqg-load-monitor.php:71`
- **Issue**: `array_merge(self::DEFAULT_THRESHOLDS, $thresholds)` permits uncontrolled array growth from filter output. Consuming code only reads known keys, but the extra keys pass through.
- **Fix**: `array_merge(self::DEFAULT_THRESHOLDS, array_intersect_key($thresholds, self::DEFAULT_THRESHOLDS))`.

### M7. Composer type mismatch
- **Wave**: Infra
- **File**: `composer.json:4`
- **Issue**: Type is `wordpress-muplugin` but plugin lives in `wp-content/plugins/`. `composer/installers` is not required, so the type has no effect. AGENTS.md and ARCHITECTURE.md describe it as a "Must-Use Plugin" which may also be inconsistent with actual deployment.
- **Fix**: Change to `"type": "wordpress-plugin"` to match actual install path, or add a note explaining intended deployment target.

### M8. ARCHITECTURE.md references "refresh" for Mutex Guard
- **Wave**: Infra
- **File**: `ARCHITECTURE.md:139`
- **Issue**: "Release and refresh use the trailing nonce as a CAS predicate" — but `refresh_lock()` doesn't exist (deferred to Phase 2). Also, CHANGELOG says "Five static primitives" counting `with_lock` as a peer, while ARCHITECTURE.md correctly treats it as a convenience wrapper over four primitives.
- **Fix**: Change "Release and refresh" to "Release and force_release". Reconcile CHANGELOG wording.

### M9. Memoized $throttle_runtime not cleared between test cases
- **Wave**: B
- **File**: `hypercart-query-guard.php:160`, `tests/ActionThrottleTest.php`
- **Issue**: Static `$throttle_runtime` array is populated by `get_throttle_decision()` and `maybe_log_throttle_events()`. Test bootstrap doesn't reset it. Not an active bug (no tests exercise these functions yet), but a latent test infrastructure gap.
- **Fix**: Add `ReflectionProperty` reset for `$throttle_runtime` in `setUp()`, or add a `reset_runtime()` test helper.

### M10. Zero test coverage for APCu backend paths
- **Wave**: A
- **Files**: `class-hcqg-load-monitor.php:91-93,115-119,154-155,167-169` (untested)
- **Issue**: Test suite exercises `db_fallback` and `persistent_object_cache` backends but never the `apcu` backend. Combined with the H1 APCu key collision bug, this gap is significant.
- **Fix**: Add APCu function stubs to the test bootstrap and write tests for the APCu code path.

---

## LOW — Polish, minor gaps

### L1. $_REQUEST['action'] without nonce verification
- **Wave**: B
- **File**: `hypercart-query-guard.php:792`
- **Issue**: Used for context detection only, not authorization. WPCS will flag it.
- **Fix**: Add `// phpcs:ignore WordPress.Security.NonceVerification.Recommended` comment.

### L2. as_get_datetime_object called without function_exists guard
- **Wave**: B
- **File**: `hypercart-query-guard.php:675`
- **Issue**: If AS is not loaded, this fatals. Upstream try/catch catches it, but the pattern is fragile.
- **Fix**: Add `function_exists('as_get_datetime_object')` guard or construct DateTime manually.

### L3. with_lock discards callable return value
- **Wave**: C
- **File**: `class-hcqg-mutex-guard.php:250-261`
- **Issue**: `call_user_func($work)` return value is discarded; `with_lock()` always returns `true`. Callers needing the result must use closure-captured variables.
- **Fix**: Document the limitation, or evolve the signature in Phase 2.

### L4. force_release TOCTOU between peek and delete
- **Wave**: C
- **File**: `class-hcqg-mutex-guard.php:213-219`
- **Issue**: Between the peek (SELECT) and the delete, another process could acquire. The force_release would delete the new holder's lock. Acceptable for an admin recovery tool.
- **Note**: Inherent to the unconditional-delete design; document for operators.

### L5. Log level routing falls through to warn for unexpected levels
- **Wave**: C
- **File**: `class-hcqg-mutex-guard.php:370-388`
- **Issue**: If `$level` is an unexpected value (e.g., 'debug'), it falls through to `warn()` — a silent severity upgrade.
- **Fix**: Minor; add a comment or stricter routing.

### L6. get_registry rebuilds on every get_priority call
- **Wave**: A
- **File**: `class-hcqg-priority-registry.php:113`
- **Issue**: No memoization. Each `get_priority()` call triggers `apply_filters()` and rebuilds the registry. Minor performance concern with many AS actions per request.
- **Fix**: Add static cache similar to `HCQG_Load_Monitor::$state_cache` if performance becomes an issue.

### L7. time() in evaluate_level makes it non-deterministic
- **Wave**: A
- **File**: `class-hcqg-load-monitor.php:247`
- **Issue**: Direct `time()` call makes the method harder to test precisely. Tests work around it with timestamps far in the past.
- **Fix**: Add an optional `$now` parameter defaulting to `time()` for testability.

### L8. phpunit.xml.dist uses deprecated PHPUnit 9 attributes
- **Wave**: Infra
- **File**: `phpunit.xml.dist:7-9`
- **Issue**: `convertErrorsToExceptions`, `convertWarningsToExceptions`, `convertNoticesToExceptions` were removed in PHPUnit 10. Fine for now (pinned to `^9.6`), but blocks future upgrade.
- **Fix**: Remove when upgrading to PHPUnit 10+.

### L9. add_filter/add_action stubs ignore priority and accepted_args
- **Wave**: Infra
- **File**: `tests/bootstrap.php:127,178`
- **Issue**: Stubs accept `($tag, callable $callback)` only. Production code passes priority as 3rd arg. Safe today (those code paths unreachable in tests), but forward-compatibility concern.
- **Fix**: Add `$priority = 10, $accepted_args = 1` parameters to stub signatures.

### L10. CHANGELOG ambiguous wording
- **Wave**: Infra
- **File**: `CHANGELOG.md:29`
- **Issue**: "over JSON" reads ambiguously — could mean "encoded over JSON" instead of the intended "chosen over JSON."
- **Fix**: Reword to "delimited `expires_at|nonce` format (chosen over JSON for portability...)".

### L11. add_option and update_option stubs have incomplete signatures
- **Wave**: Infra
- **File**: `tests/bootstrap.php:140,149`
- **Issue**: `add_option` stub drops `$deprecated` and `$autoload` params. `update_option` drops `$autoload`. Production code passes these; PHP silently ignores extra args.
- **Fix**: Add missing parameters to stub signatures for completeness.

### L12. Missing test edge cases
- **Wave**: A + B
- **Issue**: No tests for: regex metacharacters in priority patterns, empty string hook name, catch-all `*` pattern, `evaluate_level` with previous=critical + no signals, `add_option` vs `update_option` branching in persist_state, empty/edge-case inputs for `defer_count_key`.

---

## Test Coverage Summary

| Area | Status | Tests |
|------|--------|-------|
| Load Monitor — evaluate_level, persist_state, read_state | Well covered | 20 |
| Priority Registry — all public methods | Well covered | 14 |
| Action Throttle — delay matrix, defer counting | Well covered | 15 |
| Mutex Guard — acquire, release, peek, force_release, with_lock | Well covered | 21 |
| **Load Monitor — collect_metrics, probes, run_mysqli_query** | **Not covered** | 0 |
| **Load Monitor — APCu backend paths** | **Not covered** | 0 |
| **Action Throttle — decision, deferral, cloning integration** | **Not covered** | 0 |
| **Observe-mode deduplication, max-defer safety valve** | **Not covered** | 0 |
