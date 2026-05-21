# Architecture

A high-level map of how the plugin is put together. Operational guidance — what to set, what to watch — lives in [README.md](README.md); this document is for engineers reading or modifying the code.

## Three independent subsystems

The plugin solves three related but separable problems and runs them as independent subsystems with their own modes, hooks, and state:

1. **Query timeout circuit breaker** — `SET SESSION MAX_EXECUTION_TIME` early in each request, log kills, surface admin recovery notices.
2. **Action Scheduler throttle** — under sustained MySQL load, slow the AS queue runner (Wave A) and defer individual low-priority actions (Wave B).
3. **Mutex Guard** — coalesce concurrent invocations of the same operation (Wave C). Independent of MySQL load; addresses thundering-herd patterns where the *same* expensive operation gets started N times in parallel.

The subsystems share a request, a logger, and the request-context detection helper. They do **not** share modes (`HYPERCART_QUERY_GUARD_MODE` controls #1; `HYPERCART_QUERY_GUARD_THROTTLE_MODE` controls #2; #3 has no mode constant — it's an opt-in primitive that callers use directly). Any of the three can be disabled without affecting the others.

## File layout

```
hypercart-query-guard.php       # Plugin entrypoint + Hypercart_Query_Guard
db.php                          # Optional wp-content/db.php drop-in (pre-init limits + conditional backtracing)
class-hcqg-load-monitor.php     # HCQG_Load_Monitor (probes, hysteresis, persistence)
class-hcqg-priority-registry.php # HCQG_Priority_Registry (hook → tier resolution)
class-hcqg-mutex-guard.php      # HCQG_Mutex_Guard (atomic acquire/release on wp_options)
tests/
  bootstrap.php                 # WP function stubs + $wpdb stub + plugin require
  PriorityRegistryTest.php
  LoadMonitorTest.php
  ActionThrottleTest.php
  MutexGuardTest.php
```

Why a class per subsystem:
- `HCQG_Load_Monitor` is pure-ish (filter calls aside, the hysteresis math is a pure function), so it's the easiest unit to test independently.
- `HCQG_Priority_Registry` has a public filter contract and is consumed by both Wave A's logging and Wave B's deferral logic; isolating it gives a single source of truth for tier resolution.
- `HCQG_Mutex_Guard` has its own contract (raw `$wpdb` SQL, `wp_options` storage, atomic `INSERT … ON DUPLICATE KEY UPDATE`) that's intentionally distinct from the WP-API-shaped code in the rest of the plugin. Isolating it makes that contract loud rather than buried.
- `Hypercart_Query_Guard` is the orchestrator for subsystems 1 and 2: it owns request-context detection, mode resolution, hook registration, and the throttle-decision pipeline. It's the layer with the most WordPress / Action Scheduler dependencies.

## Subsystem 1: Query timeout circuit breaker

### Lifecycle
```
db.php present  ─► HCQG_DB::query() first query ─► SET SESSION MAX_EXECUTION_TIME = default
              │
init priority 1 ─► apply_session_timeout() ──► SET SESSION MAX_EXECUTION_TIME = context ceiling
              │
              ├─► query filter ─► capture_pending_kill_filter() ─► capture_pending_kill()
              │   (runs before each subsequent wpdb::query, catches mid-request kills)
              │
              └─► shutdown ─► detect_and_log_kill()
                              log_slow_queries() (if SAVEQUERIES sampled)
```

### Context detection
[`Hypercart_Query_Guard::detect_context()`](hypercart-query-guard.php) returns one of: `wp_cli`, `action_scheduler`, `wp_cron`, `checkout`, `admin_ajax`, `rest_api`, `wp_admin`, `frontend`. Order matters — Action Scheduler must be detected *before* `wp_doing_cron()` / `wp_doing_ajax()` because both AS transports masquerade as those contexts at `init` priority 1, before AS's own hooks have fired.

The detected context maps to a ceiling via `LIMITS_MS` (e.g. `wp_admin: 45_000`, `rest_api: 30_000`, `action_scheduler: 0` = unlimited).

Query timeout now carries a second dimension: consequence tier (`invisible`, `retry_safe`, `user_visible`, `transactional`). The timeout resolver computes context first, then resolves tier via `hypercart_query_guard_consequence_tier`, then applies the context × tier matrix from `hypercart_query_guard_context_consequence_limits_ms`. Default matrix values are generated from `LIMITS_MS` (single source of truth), so behavior is unchanged until tuned.

Kill/slow-query telemetry prefers the timeout policy snapshot that was actually applied via `SET SESSION`, then falls back to live resolution only when no applied snapshot exists.

### Reconnect handling
Without the drop-in, `apply_session_timeout()` memoizes `$wpdb->dbh` identity. When WPE / Kinsta rotate the MySQL connection mid-request, `$wpdb->dbh` becomes a new object, the identity check fails, and the timeout is re-applied automatically.

With the v2 `db.php` drop-in, `HCQG_DB` applies the pre-init default on the first query, then the MU-plugin calls `hcqg_update_limit()` at `init` with the resolved context ceiling. A ceiling of `0` means unlimited and is explicitly applied so contexts such as Action Scheduler clear the pre-init default.

### Multi-kill capture
A naïve "log on shutdown" approach loses every kill except the last, because each subsequent `wpdb::query()` overwrites `$wpdb->last_error`. The `query` filter captures pending errors before the next query clears them, and a `$wpdb->num_queries` high-water mark makes the capture idempotent so the NoFraud thundering-herd pattern (multiple kills with the same error string) is correctly counted as distinct events.

## Subsystem 2: Action Scheduler throttle

### Decision pipeline (single request)
```
queue runner filter or before_execute hook fires
        │
        ▼
 Hypercart_Query_Guard::get_throttle_decision()  ◄─── memoized per request
        │
        ├─► HCQG_Load_Monitor::collect_metrics()  ◄── direct mysqli probes
        │     ├─ Threads_running (often blocked on managed hosts)
        │     └─ actionscheduler_actions due-queue depth
        │
        ├─► HCQG_Load_Monitor::read_state()       ◄── persistent backend
        │     (level + changed_at from prior request)
        │
        ├─► HCQG_Load_Monitor::evaluate_level()
        │     hysteresis exits + minimum dwell
        │     (entry/exit thresholds asymmetric to prevent flapping)
        │
        └─► HCQG_Load_Monitor::persist_state()    ◄── persistent backend
              (carry the new level forward for next request)
```

The decision array carries: requested mode, effective mode (after capability checks), cache backend in use, current load level, raw level (pre-dwell), policy and matrix snapshots, and the metric probe results.

### Wave A: queue-runner throttling
Three filters cap AS queue runner parameters by load level:
- `action_scheduler_queue_runner_batch_size`
- `action_scheduler_queue_runner_time_limit`
- `action_scheduler_queue_runner_concurrent_batches`

Caps are `min(existing, policy_for_level)` so we only ever reduce, never raise.

### Wave B: per-action deferral
On `action_scheduler_before_execute`, [`maybe_defer_action_before_execute()`](hypercart-query-guard.php):

1. Bails if level is `normal`.
2. Fetches the action and resolves its priority tier via `HCQG_Priority_Registry::get_priority($hook)`.
3. Bails if the schedule is recurring (cancelling a recurring instance breaks AS's recurrence chain).
4. Looks up `(level, tier)` in the delay matrix; bails if delay is 0.
5. Checks the per-(hook, args, group) defer-count cap; bails if exceeded.
6. In `enforce`: builds a single-shot clone for `now + delay`, saves it, then `cancel_action()` + `unclaim_action()` on the original. AS's `process_action` re-checks status after `before_execute` and short-circuits with `execution_ignored` when status is no longer `pending`, so the original callback never fires.
7. Logs `as_action_deferred` (deduped per (hook, level) per request in observe mode to bound log volume).

### Why the priority registry is its own class
`HCQG_Priority_Registry` enforces a strict filter contract: tier list is fixed (`critical`, `high`, `normal`, `deferrable`) and always evaluated in declaration order; filters can override patterns within a tier, clear a tier (empty array), or be ignored if they return unknown tiers. This invariant matters because Wave B's matrix is keyed by tier — a filter that leaks an unrecognized tier would silently produce "no defer" instead of erroring.

## Subsystem 3: Mutex Guard

Coalesce concurrent invocations of the same operation across all PHP workers and (when the database is shared) all hosts. Independent of the throttle subsystem — usable with `HYPERCART_QUERY_GUARD_THROTTLE_MODE = 'off'`.

### Atomic acquire flow
```
HCQG_Mutex_Guard::acquire_lock( $key, $ttl )
        │
        ▼
 INSERT INTO wp_options (option_name, option_value, autoload)
   VALUES (hcqg_mutex_<md5($key)>, '<expires_at>|<nonce>', 'no')
   ON DUPLICATE KEY UPDATE option_value = IF(
     CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) < <time()>,  ◄── PHP-anchored
     VALUES(option_value),
     option_value
   )
        │
        ▼
 inspect $wpdb->rows_affected
   1 → fresh INSERT ........... acquired (return nonce)
   2 → UPDATE replaced expired ► acquired (return nonce)
   0 → live lock held .......... return false (log mutex_held)
```

Three correctness invariants the class enforces, documented loudly in the file header because they're easy to break with a well-meaning refactor:

1. **No `add_option`/`update_option`.** Read-then-write is TOCTOU; only `INSERT … ON DUPLICATE KEY UPDATE` is atomic at the row level.
2. **No `UNIX_TIMESTAMP()` in SQL.** Time anchoring uses PHP's `time()` passed as a `%d` parameter so web/DB clock skew on managed hosts can't make the effective TTL differ from the requested TTL.
3. **No per-request memoization on acquire.** Memoization creates a same-process reentrancy hole — A acquires, B "acquires" via memo, A releases, an external process takes the lock, A and B both run the work. Each `acquire_lock` call is one bounded SQL statement; in steady state under contention, the first caller wins and every peer gets `rows_affected = 0` and returns false. There's no thrashing to mitigate.

### Lock value format
`expires_at|nonce` delimited string (e.g. `1739564821|a4f9c2e8b1d3f607`). The nonce is 16 hex chars from `random_bytes(8)`. Release and force_release use the trailing nonce as a CAS predicate (`SUBSTRING_INDEX(option_value, '|', -1) = %s`) so a process whose work overran TTL doesn't accidentally release the next holder's lock when it finally calls `release_lock()`.

The delimited shape (over JSON) makes `SUBSTRING_INDEX` extraction work on every supported MySQL/MariaDB version without depending on `JSON_EXTRACT()`.

### Option name encoding
`option_name` is `'hcqg_mutex_' . md5($operation_key)` (43 chars, fixed) so caller-supplied keys can be arbitrary length without overflowing `wp_options.option_name`'s `varchar(191)` limit. The raw key flows through to log payloads so operators investigating contention see human-readable keys, not hashes.

### State model

| State | Lives in | TTL | Notes |
| --- | --- | --- | --- |
| Detected request context | computed on read | request | No dedicated memo; recomputed by resolver helpers |
| Timeout policy snapshot | static memo on `Hypercart_Query_Guard` | request | `resolved_policy` records latest resolution; `applied_policy` set only after successful `SET SESSION` (or same-connection/same-limit short-circuit) |
| Throttle decision | static memo on `Hypercart_Query_Guard` | request | One probe per request, regardless of how many queue-runner filters fire |
| Load level + transition timestamp | persistent object cache → APCu → wp_options | `dwell × 4`, min 60s | Cross-request hysteresis state; option fallback is low-write (only written when level transitions) |
| Defer count per (hook, args, group) | object cache | 1 hour | Best-effort cap; resets per-request on hosts without persistent caching |
| Mutex lock rows | wp_options (autoload no) | caller-supplied TTL via embedded `expires_at` | One row per active mutex key, hash-keyed; expired rows are ignored on read and overwritten on next acquire |
| Admin search recovery notice | transient | 60s | Per-user, cleared on first render |
| Cache backend selection | static memo on `HCQG_Load_Monitor` | request | Computed once, never recomputed |

`HCQG_Load_Monitor::get_cache_backend()` picks the strongest available backend at request start: `persistent_object_cache` (if `wp_using_ext_object_cache()`), then `apcu` (if loaded and enabled in this SAPI), then `db_fallback` (low-write option). Behavior is the same across all three; only the storage substrate differs.

## Mode matrix

Two independent mode dimensions for subsystems 1 and 2; subsystem 3 has no mode constant.

| `HYPERCART_QUERY_GUARD_MODE` | Effect |
| --- | --- |
| `off` | No SET SESSION, no logging |
| `observe` *(default)* | SAVEQUERIES sampled at 5%, slow queries logged, no kills |
| `enforce` | SET SESSION applied, kills logged, admin notice rendered |

| `HYPERCART_QUERY_GUARD_THROTTLE_MODE` | Effect |
| --- | --- |
| `off` *(default)* | No load probing, no queue-runner throttle, no per-action defer |
| `test_observe` | Probes + capability log only; no per-action evaluation (cheapest way to validate signals on a managed host) |
| `observe` | Full throttle decision computed and logged; no behavior change |
| `enforce` | Queue-runner caps applied; per-action defers via clone-and-cancel |

`enforce` for the throttle can downgrade to `observe` at runtime if the `hypercart_query_guard_throttle_require_persistent_cache` filter is true and no persistent backend is available — the decision carries a `blocked_reason: persistent_cache_required` field for visibility.

The Mutex Guard has no mode — it's an opt-in primitive. Callers either invoke it or they don't; there's no global "do nothing" switch because the subsystem does nothing on its own.

## Extension points (the public-ish API)

These are the filters the rest of the codebase commits to keeping stable. Adding new filters is fine; renaming or removing these is a breaking change.

**Mode / capability**
- `hypercart_query_guard_throttle_mode` — override the mode resolved from the constant
- `hypercart_query_guard_throttle_enabled` — last-line kill switch
- `hypercart_query_guard_throttle_require_persistent_cache` — enforce → observe downgrade if no persistent cache

**Tuning**
- `hypercart_query_guard_load_thresholds` — entry/exit thresholds, dwell duration
- `hypercart_query_guard_throttle_policy` — Wave A queue-runner caps per level
- `hypercart_query_guard_action_delay_matrix` — Wave B (level × tier) → seconds
- `hypercart_query_guard_max_defer_count` — per-signature defer cap
- `hypercart_query_guard_limit_ms` — final query timeout after context + consequence resolution (subsystem 1)
- `hypercart_query_guard_consequence_tier` — request consequence classification for subsystem 1
- `hypercart_query_guard_context_consequence_limits_ms` — context × consequence timeout matrix for subsystem 1

**Routing**
- `hypercart_query_guard_priority_registry` — tier → patterns map
- `hypercart_query_guard_action_priority` — final per-hook tier override (validated against `HCQG_Priority_Registry::TIERS`)

The two registry filters use **extend-not-replace** semantics: omitted tiers / cells inherit defaults; pass an explicit empty array (or 0) to clear.

**Mutex Guard (subsystem 3)**
The four primitives plus the `with_lock()` convenience wrapper are static methods on `HCQG_Mutex_Guard`; there's no filter surface in v1. Callers compose them directly. Default TTL (`60s`) is the only currently-tunable knob, and it's a per-call argument rather than a global filter.

## Logging

All structured records go through `Hypercart_Query_Guard::log()`, which prefers `Hypercart_Logger` if present (the file-based logger from the Performance Monitor plugin) and falls back to `error_log()` with single-line JSON for grep-ability. Every record carries an `event` field; the full event vocabulary is documented in [README.md](README.md#logging).

## Testing model

The suite is deliberately WP-free. [`tests/bootstrap.php`](tests/bootstrap.php) stubs the small set of WordPress functions the plugin actually touches (`apply_filters`, `wp_cache_*`, `get_option`, `wp_using_ext_object_cache`, etc.) with stateful in-process implementations resettable between tests via `WP_Stub_State::reset()`. For the Mutex Guard subsystem, a minimal `WP_Stub_DB` stub models the surface of `$wpdb` the SUT touches — `prepare()` / `query()` / `get_var()` / `rows_affected` / `options` — with FIFO queues that tests use to stage return values and an SQL capture array for query-shape assertions.

The bootstrap also forces both modes to `off` before requiring `hypercart-query-guard.php`, so `Hypercart_Query_Guard::init()` early-returns instead of registering hooks against the (non-existent) WP runtime.

Pure functions are tested directly. Private statics are tested via `ReflectionMethod`. Anything that requires a real Action Scheduler (`build_deferred_action_clone`, `defer_action`, the actual `before_execute` orchestration) is **not** unit-tested — that's deliberate. The same gap applies to the Mutex Guard's actual MySQL semantics (`INSERT ... ON DUPLICATE KEY UPDATE` atomicity, `rows_affected = 2` on the expired-takeover branch): unit tests cover query shape, return mapping, and serialization, but the SQL itself is validated through staging integration during rollout. These gaps are explicit, not accidental.

Run with `composer install && vendor/bin/phpunit`.

## Non-goals

What this plugin doesn't do, and why each gap is intentional, is enumerated under "Limitations and caveats" in [README.md](README.md). The architectural decisions that underlie those gaps:

- The `init` priority 1 hook is the earliest reliable point without a `db.php` drop-in. Anything before that (autoloaded options preload, auth, WC session bootstrap) is unprotected. The v2 path is a drop-in.
- `MAX_EXECUTION_TIME` is read-only by MySQL design. We don't kill writes — that's a feature, not an oversight.
- The throttle is reactive (load → defer), not predictive. We do not model action runtimes or arrival rates.
