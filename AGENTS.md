# Agent Guidelines for Hypercart Query Guard

Welcome to the `queryguard-plugin` repository. This is a mission-critical infrastructure plugin (Must-Use Plugin / MU-Plugin) for WordPress. Any bugs, performance regressions, or architectural missteps here can cause site-wide outages. 

AI agents and human contributors must strictly adhere to the following architectural, formatting, and behavioral guidelines to maintain the current high standards of code quality (DRY, SOLID).

## 0. Start Here: Read the Map Before Scanning the Codebase

**At the start of every new agent session, read [ARCHITECTURE.md](ARCHITECTURE.md) first.** It is the project's index — designed to be a fast, complete map so you can jump to the relevant subsystem instead of scanning the whole repo.

Specifically, ARCHITECTURE.md tells you:

*   **Which of the three subsystems your task lives in** (query timeout circuit breaker / Action Scheduler throttle / Mutex Guard) and which file owns it.
*   **The file layout** with one-line responsibility hints for every `class-hcqg-*.php` and every test file.
*   **The decision pipelines** (subsystem 2's throttle decision, subsystem 3's atomic acquire flow) so you can trace request behavior without re-deriving it from `grep`.
*   **The state model** — what's persisted, where, with what TTL, and which class owns the read/write path.
*   **The mode matrix** — what each `HYPERCART_QUERY_GUARD_MODE` and `HYPERCART_QUERY_GUARD_THROTTLE_MODE` value actually does.
*   **The public-ish filter surface** — which filters are stable and which are internal.
*   **The testing model** — what's unit-tested, what's deliberately integration-only, and why each gap exists.

After reading ARCHITECTURE.md, scope your search to the named files for the subsystem you're touching. Reading one focused class is almost always faster than `grep`-ing the whole codebase, and matches the SRP boundaries in section 1 below.

If you find a discrepancy between ARCHITECTURE.md and the code, that's a bug — flag it before proceeding rather than silently picking one as authoritative.

## 1. Architectural Principles (SOLID)

This codebase uses a pragmatic application of SOLID principles adapted for the WordPress environment. 

*   **Single Responsibility Principle (SRP):** 
    *   Do not create "God objects" or dump new features into `hypercart-query-guard.php`. 
    *   Every distinct subsystem MUST live in its own `class-hcqg-*.php` file (e.g., Load Monitor, Priority Registry, Mutex Guard).
    *   Each class should have one clear reason to change.
*   **Open/Closed Principle (OCP):**
    *   The core logic is closed for modification but open for extension. 
    *   Never hardcode client-specific or store-specific configurations. Expose them via `apply_filters()`. 
    *   Document the filter contracts clearly in the docblocks.
*   **Dependency Inversion Principle (DIP):**
    *   Avoid hard dependencies on global state when possible. When interacting with WordPress globals (like `$wpdb`), encapsulate the interaction safely (e.g., checking for `mysqli` vs. custom `db.php` drop-ins) and allow fallback mechanisms.
    *   Make external state (like caching) configurable or auto-detecting (e.g., `wp_using_ext_object_cache()`, `apcu`, or DB fallbacks).

## 2. DRY (Don't Repeat Yourself) & Performance

Performance is paramount. This plugin runs on every single request.

*   **Memoize read-heavy reactive state, never atomic primitives.** Throttle decisions, load probes, cache backend selection, request-context detection — these are read-heavy and reactive; the SUT does not get worse if N callers in the same request see the same answer, so they MUST be memoized statically per-request to avoid redundant work. **Do not memoize atomic primitives** (e.g. `HCQG_Mutex_Guard::acquire_lock()`). Memoizing a primitive whose correctness depends on hitting authoritative storage every call creates a same-process reentrancy hole: caller A acquires, caller B "acquires" via the memo without a DB write, A releases, an external process takes the lock while B is still running, and the primitive is silently defeated. If you're tempted to memoize an `acquire_*` / `claim_*` / `lock_*` method, stop and re-read its class header.
*   **Centralize Constants:** Magic strings, default thresholds, and fixed configurations must be declared as class `const` variables.
*   **Unified Logging:** Never use raw `error_log()` outside of a class-level `log()` helper. Each subsystem class carries its own `private static log()` that prefers `Hypercart_Logger` (the file-based logger from the Performance Monitor plugin) and falls back to `error_log()` with single-line JSON for grep-ability. The fallback chain is duplicated by design — making `log()` cross-class-public would couple the subsystems unnecessarily, and a 15-line helper is cheap to copy. Every record must carry an `event` field; the full vocabulary is in `README.md` and `ARCHITECTURE.md`.
*   **Database Interactions:** When you can use `wpdb`, use it — it handles connection rotation, error suppression, and the `SAVEQUERIES` accounting. When you can't (e.g. probes that must avoid polluting `wpdb` state, or atomic SQL that requires raw `INSERT ... ON DUPLICATE KEY UPDATE`), follow the safety pattern in `HCQG_Load_Monitor` and `HCQG_Mutex_Guard`: guard `$wpdb->dbh instanceof mysqli` for raw mysqli paths, anchor any time comparisons to PHP's `time()` passed as a parameter (never `UNIX_TIMESTAMP()`), and prefer `INSERT ... ON DUPLICATE KEY UPDATE` over read-then-write for anything that can race.

## 3. WordPress MU-Plugin Constraints

*   **No Autoloaders / Dependency Injection Containers:** This plugin loads at `muplugins_loaded` (or earlier if included in `db.php`). We do not use Composer autoloaders for the core plugin logic. Keep `require_once` statements manual and explicit in the main plugin file.
*   **Static `final` Classes:** The established pattern for subsystems in this codebase is `final class` with `public static` methods. Do not introduce instantiated objects or inheritance hierarchies unless absolutely necessary.
*   **Early Hook Execution:** Be extremely careful about what WordPress functions you call on `init` priority 1 or earlier. Many standard WP functions or globals are not yet available.

## 4. Safety and Error Handling

*   **Never fatal a request, but do default defensively.** A probe error or unexpected response should never throw an uncaught exception that halts the request. Beyond that, "fail open" vs "fail closed" is **subsystem-specific** and you must match the existing convention rather than picking one:
    *   **Throttle (Wave A/B): fails closed to `LEVEL_ELEVATED`** when both load detectors are unavailable. Failing to NORMAL would mean "no throttling" exactly when the system can't see itself, which is the worst time to run unprotected. See `HCQG_Load_Monitor::evaluate_level()`'s detector-mode `'none'` branch.
    *   **Query timeout (subsystem 1): fails open** — a `SET SESSION` failure suppresses errors and lets the request continue without the ceiling, because the alternative (refusing to serve traffic) is worse than the alternative (running a request without the cap).
    *   **Mutex Guard (Wave C): fails closed to `false`** on any DB-side error or absence — `acquire_lock()` returning `false` means "someone else is doing it / I can't tell," and the documented caller patterns handle that case.
*   **Strict Type Checking:** Although this is PHP 7.4+, treat types strictly. Cast variables `(int)`, `(string)`, `(bool)` explicitly when reading from the database, filters, or external inputs.
*   **Namespace / Prefixing:** All classes must be prefixed with `HCQG_` or `Hypercart_`. All options, transients, and cache keys must be prefixed with `hcqg_` or `hypercart_query_guard_`.

## 5. Coding Standards

*   **WordPress Coding Standards (WPCS):** Strictly follow WPCS formatting.
    *   Use tabs for indentation.
    *   Spaces inside parentheses: `if ( $condition ) { ... }`.
    *   Yoda conditions are optional but encouraged for strict equality: `if ( true === $foo )`.
*   **Docblocks:** Every class, every public method, and every filter MUST have a comprehensive PHPDoc block explaining purpose, parameters, return type, and (for filters) the contract — what types are accepted, what's ignored, what extend-vs-replace semantics apply. Constants get a docblock when the name doesn't make the meaning obvious; trivial constants (`const NONCE_BYTES = 8;`) don't. Private helpers get a one-line `@return` and parameter docblock if the type signature isn't already clear.

## 6. Testing

*   **Unit Tests:** Any new subsystem must have PHPUnit coverage for the parts that are unit-testable against the existing bootstrap. Use the provided stubs in `tests/bootstrap.php` (`WP_Stub_State` for the WP function layer, `WP_Stub_DB` for the `$wpdb` surface). Test pure logic directly; reach for `ReflectionMethod` for private statics rather than making them public to test them.
*   **Integration-only paths are explicit, not accidental.** Some paths cannot be unit-tested without standing up a real WordPress + WooCommerce + Action Scheduler stack — Wave B's `before_execute` orchestration, the Mutex Guard's actual `INSERT ... ON DUPLICATE KEY UPDATE` atomicity and `rows_affected = 2` semantics, and any code path that depends on AS internals. These are validated through the documented rollout sequence (`test_observe` → `observe` → `enforce` for the throttle; staging-then-one-store for the mutex). When you add code that falls into this category, document the gap in the design doc and the test file rather than papering over it.
*   **Side Effects:** Isolate side effects (like actual database writes or API calls) from the core logic so the logic can be tested independently.

---
**Agent Directive:** When writing or modifying code in this repository, you must verify your changes against this document. Do not propose refactors that introduce complex object-oriented patterns (like interfaces or factories) that violate the "Static `final` Classes" constraint unless specifically directed by the user.