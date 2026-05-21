# Session Summary — 2026-05-14

Recap of recent work and the next task (V2 db.php drop-in).

---

## Last 2 commits

### `146e0ed` — Fix pre-production review items: harden filters, atomics, error paths
**Status:** most recent, **not pushed**

Addresses the "Fix Before Production" items from review issues #29/#30, PR #28:

- **H2** Removed redundant `unclaim_action` after `cancel_action` (AS cancel makes the claim moot)
- **M3** `get_throttle_policy()` filter output now normalized using the same pattern as `get_action_delay_matrix()`
- **M2** Atomic defer counting via `wp_cache_add` + `wp_cache_incr`, with read-modify-write fallback for non-persistent backends
- **M4** Replaced `mt_rand` fallback in `generate_nonce()` with `openssl_random_pseudo_bytes`; re-throws if neither entropy source is available
- **M5** Check `$wpdb->last_error` after acquire/release queries; distinct `mutex_acquire_error` / `mutex_release_error` events so DB failures are not misread as contention
- **M6** `get_thresholds()` filters unknown keys via `array_intersect_key` against `DEFAULT_THRESHOLDS`
- **M7** `composer.json` `type` changed `wordpress-muplugin` → `wordpress-plugin` to match actual install path

All 70 tests pass.

Files touched:
- `class-hcqg-load-monitor.php`
- `class-hcqg-mutex-guard.php`
- `composer.json`
- `hypercart-query-guard.php`
- `tests/bootstrap.php`

### `e4dc882` — Fix review findings: sanitize log inputs, namespace APCu key, widen hash
**Status:** pushed

"Fix Now" items from the same code review:

- **H1** APCu key prefixed with `CACHE_GROUP` to prevent cross-site collision on shared hosting
- **H3** Sanitize `$_SERVER['REQUEST_URI']` with `sanitize_text_field` / `wp_unslash` in all 5 log payload locations (plus missing WP stubs added in bootstrap)
- **M1** `defer_count_key` uses md5 instead of CRC32 to avoid 32-bit collisions
- **H4** Safety comment on `VALUE_DELIMITER` explaining the SQL interpolation contract
- **M8** Doc reconciliation: ARCHITECTURE.md "refresh" → "force_release"; CHANGELOG "Five primitives" → "Four primitives plus wrapper"; fixed "over JSON" ambiguity

All 70 tests pass.

Files touched:
- `ARCHITECTURE.md`
- `CHANGELOG.md`
- `PROJECT/2-WORKING/CODE-REVIEW-ce853ba-to-e27825c.md`
- `class-hcqg-load-monitor.php`
- `class-hcqg-mutex-guard.php`
- `hypercart-query-guard.php`
- `tests/bootstrap.php`

---

## `query-guard-findings.md` review

Field report from Norman's Nursery (WP Engine, `/nas/content/live/nnwebsite2023/`), 12-hour log window on 2026-05-14.

### Key points

1. **Plugin is correctly installed** as an mu-plugin. Fingerprint confirmed `Hypercart Query Guard v1.0.0`.
2. **Running on defaults**: `observe` mode, 5% sample rate, 5s warn threshold, throttling `off`. No `HYPERCART_QUERY_GUARD_*` constants set in `wp-config.php`.
3. **Zero query guard log lines in 12 hours.** Expected given the defaults — 5% sampling × needing > 5s queries = silence on a healthy site. Working as designed.
4. **Hypercart Helper is not installed** on this site, so the guard would have fallen back to `error_log()` if any slow queries had occurred.

### The V2 motivation (lines 122–163)

The doc clearly diagnoses why V2 is needed. Core's `SAVEQUERIES` path inside `wpdb::query()`:

```
1. start timer
2. run the query              ← duration unknown
3. stop timer                 ← duration NOW known
4. debug_backtrace()          ← expensive, runs unconditionally
5. store query + duration + backtrace
```

Step 4 fires on every query even though step 3 already knows the duration. On a WC page (~300 queries):

| Cost | Estimate | % of normal work |
|------|----------|------------------|
| Extra CPU | ~30ms (300 × ~0.1ms per backtrace) | **~10%** |
| Extra memory | ~1MB | ~1.5% |

Site-wide CPU overhead scales linearly with sample rate:
- 5% (default) → ~0.5%
- 25% → ~2.5%
- 100% → ~10%

That ~10% at 100% sampling is what's blocking observer-at-100% before enforce-at-100%.

### Proposed GitHub issue (lines 175–226)

The doc drafts an issue body for **v2: db.php drop-in for early-query coverage and zero-overhead observation**, with three responsibilities:

1. Apply `SET SESSION MAX_EXECUTION_TIME` on first query — covers pre-`init` queries (`wp_load_alloptions()`, auth/usermeta, WC session bootstrap).
2. Conditional backtracing — time every query (microsecond cost), only call `debug_backtrace()` when duration ≥ warn threshold.
3. Retain all v1 behavior — kill detection, admin-search fallback notice, structured logging via Hypercart Logger, tiered context limits.

The doc states no existing issue tracks this (issue #12 mentions "Phase 2 (db.php drop-in)" only in passing).

### Side observations

Log noise unrelated to the guard:
- `_load_textdomain_just_in_time` notices from ACF and Astra (WP 6.7+ strictness)
- `WP_Dependencies->add_data()` deprecation for IE conditional comments (WP 6.9)
- Dynamic property deprecation in WooCommerce Side Cart Premium (PHP 8.2+)
- Undefined `$footerTxt` in the theme's side-cart footer template

---

## V2 next task — db.php drop-in

### Why

To run observer mode at 100% prior to enforce mode at 100%, the ~10% CPU overhead from unconditional `debug_backtrace()` on every query must go. The backtrace is only needed for slow queries.

### Approach

A `wp-content/db.php` drop-in that extends `wpdb` and overrides `query()` to:

- Time every query (already free — `wpdb` does this anyway)
- Call `debug_backtrace()` **only when** `duration >= warn_threshold`
- Apply `SET SESSION MAX_EXECUTION_TIME` on the first query (no `init` hook dependency, so pre-`init` queries are covered)
- Preserve all v1 behavior (modes, tiered limits, kill detection, admin-search fallback, structured logging)

This collapses the ~10% overhead to ~0% **and** picks up pre-`init` queries for free.

### Suggested next step

File the GitHub issue using the body already drafted in `query-guard-findings.md` lines 175–226, with two small additions:

1. **Acceptance criteria**
   - Overhead measured before/after on a representative WC request
   - Behavior parity with v1 enforce/observe (tests still pass; identical log events for the same conditions)
   - Safe activation/deactivation since `db.php` is a drop-in (not a plugin) — what happens if the plugin is removed but `db.php` remains, and vice versa

2. **Rollout question**
   - Does the drop-in ship inside this plugin's `mu-plugins` package and get symlinked/copied into `wp-content/db.php` by an installer?
   - Or shipped as a separate companion package?

I can open this issue against `Hypercart-Dev-Tools/queryguard-plugin` on request, showing you the final body before posting.
