# Hypercart Query Guard — Norman's Nursery Findings

**Date:** 2026-05-14
**Site:** Norman's Nursery (WP Engine: `/nas/content/live/nnwebsite2023/`)
**Log window:** May 14, 2026 06:25–18:12 UTC (~12 hours)

---

## Plugin Status

The auditor fingerprint confirms the plugin **is installed** as an mu-plugin at `wp-content/mu-plugins/hypercart-query-guard.php`. That's the correct location.

```
auditor:scan=fingerprint {"blog_id":1,"kind":"mu-plugin","name":"Hypercart Query Guard","slug":"hypercart-query-guard.php","ver":"1.0.0","sig":"v1:nohash"}
```

## Current Configuration (Defaults)

No `HYPERCART_QUERY_GUARD_MODE` or `HYPERCART_QUERY_GUARD_THROTTLE_MODE` constants appear to be defined in `wp-config.php`, so the plugin is running with defaults:

| Setting | Default | Meaning |
|---------|---------|---------|
| Mode | `observe` | Logs slow queries but does NOT enforce timeouts |
| Throttle mode | `off` | Action Scheduler throttling is disabled |
| Sample rate | 5% | Only 5% of requests have SAVEQUERIES enabled |
| Warn threshold | 5000ms | Only queries > 5 seconds get logged |

## Query Guard Output in debug.log

**Zero query guard log lines** found in ~12 hours of logs.

This is expected given the defaults — the combination of **5% sampling** AND requiring a query **> 5 seconds** to trigger a log means output will be rare unless the site has genuine runaway queries. It's working as designed; there's just nothing to report.

### Why no output?

- In `observe` mode, only 5% of requests get `SAVEQUERIES` enabled (to keep memory overhead low).
- Of those sampled requests, only queries exceeding 5 seconds are logged.
- The probability of both conditions being true on a healthy site is very low.

### Logger fallback

**Hypercart Helper is not installed** on this site (only "Hypercart - Site Copy Detector - MKIII" was fingerprinted). Without the Helper's `Hypercart_Logger` class, the query guard falls back to PHP's `error_log()`, which writes to `debug.log` with the prefix `[hypercart_query_guard]`. If slow queries had been caught, they would appear in this log.

---

## How the Plugin Works

### Two independent systems

1. **Query timeout enforcement** (`HYPERCART_QUERY_GUARD_MODE`)
   - Sets MySQL `MAX_EXECUTION_TIME` on the session to cap how long any single SELECT can run
   - Tiered limits per request context:

   | Context | Limit |
   |---------|-------|
   | WP-CLI | unlimited |
   | Action Scheduler | unlimited |
   | WP Cron | 10s |
   | Admin AJAX | 20s |
   | REST API | 30s |
   | Frontend | 30s |
   | WP Admin | 45s |
   | Checkout | 60s |

   - Three modes: `off`, `observe` (log only), `enforce` (log + kill queries)
   - In `enforce` mode, killed queries are logged and admin searches that time out show a user-facing notice instead of "no results"

2. **Action Scheduler throttling** (`HYPERCART_QUERY_GUARD_THROTTLE_MODE`)
   - Monitors MySQL `Threads_running` and AS queue depth
   - Reduces AS batch size, time limit, and concurrent batches under load
   - Uses hysteresis (elevated/critical levels with separate entry and exit thresholds) to avoid flapping
   - Four modes: `off`, `test_observe` (log capability only), `observe` (log decisions), `enforce` (actually throttle)

### Hooks timing

The plugin hooks into `init` at priority 1. This means queries fired before `init` (autoloaded options, auth/usermeta lookups, WC session bootstrap) run **without** a timeout ceiling. The plugin header notes this as a known v1 limitation — v2 would use a `db.php` drop-in to cover those early queries.

---

## How to Set Up for Testing

### Step 1: Observe with higher visibility (safe, no query killing)

Already the default mode, but output is rare due to 5% sampling. The quickest way to get data is to move to enforce mode (Step 2), which observes 100% of requests.

### Step 2: Enable enforce mode

Add to `wp-config.php`:

```php
define( 'HYPERCART_QUERY_GUARD_MODE', 'enforce' );
```

This does two things:
- Applies `SET SESSION MAX_EXECUTION_TIME` to every request (tiered by context)
- Enables SAVEQUERIES on **100% of requests** (not sampled), so all slow queries get logged

Killed queries will appear in debug.log (or Hypercart Logger if Helper is installed) with event `query_killed`. Slow queries (> 5s but not killed) appear as `slow_query`.

### Step 3: Test Action Scheduler throttling

Add to `wp-config.php`:

```php
define( 'HYPERCART_QUERY_GUARD_THROTTLE_MODE', 'test_observe' );
```

This logs an `as_throttle_capability_test` event on every AS-related request, showing:
- Cache backend detected (persistent object cache, APCu, or DB fallback)
- Current Threads_running and queue_depth metrics
- What load level would be evaluated
- What policy would be applied

Step up to `'observe'` then `'enforce'` once comfortable with the metrics.

### Step 4: Install Hypercart Helper (optional)

If the Hypercart Helper plugin is installed, query guard logs route through `Hypercart_Logger` to `wp-content/hypercart-logs/` with structured file-based logging instead of debug.log. This is cleaner for production monitoring but not required for testing.

---

## SAVEQUERIES Overhead Analysis

### The observation cost

The performance overhead of observe mode comes almost entirely from WordPress core's `SAVEQUERIES` behavior, not from the plugin's logging. When SAVEQUERIES is enabled, WordPress calls `debug_backtrace()` on **every query** in the request — not just slow ones.

### Order of operations in WordPress core (`wp-db.php`)

```
1. Start timer
2. Execute the query           ← duration unknown yet
3. Stop timer                  ← duration now known
4. Call debug_backtrace()      ← expensive, happens unconditionally
5. Store query + duration + backtrace in $wpdb->queries array
```

The plugin's shutdown handler then walks the array and only logs queries over 5 seconds. But by that point, the cost has already been paid on every query.

### Relative overhead per request

For a typical WooCommerce page (~300 queries, ~300ms PHP CPU time, ~60MB memory):

| Cost | Estimate | As % of normal work |
|------|----------|---------------------|
| Extra CPU | ~30ms (300 × ~0.1ms per backtrace) | **~10%** |
| Extra memory | ~1MB (SQL strings + backtrace strings) | **~1.5%** |

### Scaled to site-wide load by sample rate

| Sample rate | Site-wide CPU overhead |
|-------------|----------------------|
| 5% (default observe) | **~0.5%** |
| 25% | **~2.5%** |
| 100% (enforce mode) | **~10%** |

### Could the overhead be eliminated?

Yes — in theory. Since the query duration is known at step 3 (before the backtrace at step 4), a conditional backtrace is possible: time every query (nearly free), only backtrace the slow ones (expensive but rare). This would reduce the observation overhead to near zero.

However, the plugin can't do this today because steps 3-5 happen inside **WordPress core's `wpdb::query()` method**. The `SAVEQUERIES` constant is a blunt on/off switch — there's no "save queries only if slow" option, and no hook fires between "duration known" and "backtrace called."

A `db.php` drop-in that overrides `wpdb::query()` could implement conditional backtracing. This is referenced in the plugin header (lines 21-27) as the v2 plan, originally motivated by covering early queries before `init`. The SAVEQUERIES overhead problem gives a second strong reason to prioritize it.

### Practical recommendation for busy sites

Use **enforce mode** with the default 5% sample rate. The `SET SESSION MAX_EXECUTION_TIME` and kill detection work at 100% without SAVEQUERIES — they use the `query` filter and `$wpdb->last_error`, which are nearly free. The 5% sampling catches slow-but-not-killed queries on a statistical basis. The queries causing real damage get caught and logged every time regardless of sampling.

### GitHub issue status

There is **no existing issue** for the v2 db.php drop-in. The plugin header mentions it, and issue #12 references "Phase 2 (db.php drop-in)" in passing (in the context of file extraction), but no issue tracks the actual feature or the SAVEQUERIES overhead motivation. A new issue should be created.

---

## Proposed GitHub Issue: v2 db.php Drop-in

**Repo:** `Hypercart-Dev-Tools/queryguard-plugin`

**Title:** v2: db.php drop-in for early-query coverage and zero-overhead observation

**Labels:** `enhancement`, `priority: medium`, `effort: high`

**Body:**

### Problem

Two independent limitations share a single root cause: the plugin hooks `init` at priority 1 and relies on WordPress core's `SAVEQUERIES` constant.

#### 1. Early-query coverage gap

Queries fired before `init` — `wp_load_alloptions()`, auth/usermeta lookups, WC session bootstrap — run without a `MAX_EXECUTION_TIME` ceiling. On sites where these early queries are the ones that go runaway (e.g., a bloated autoload table or a cold object cache miss), v1 enforce mode provides no protection.

Already noted in the plugin header (lines 21-27).

#### 2. SAVEQUERIES observation overhead

WordPress core's `SAVEQUERIES` calls `debug_backtrace()` on **every query** unconditionally — before checking duration, before anything. On a WooCommerce page with ~300 queries, that adds ~30ms of CPU (~10% overhead) and ~1MB of memory per request. This is why v1 defaults to 5% sampling in observe mode.

The irony: query duration is already known before the backtrace runs. Core just doesn't check it. A conditional backtrace (time every query cheaply, only backtrace the slow ones) would reduce observation overhead to near zero, making 100% observation viable on busy production sites.

### Solution

A `wp-content/db.php` drop-in that extends `wpdb` and overrides `query()` to:

1. **Apply `SET SESSION MAX_EXECUTION_TIME` on first query** — covers everything from the very first `wp_load_alloptions()` call, no hook dependency.

2. **Implement conditional backtracing** — time every query (microsecond cost), only call `debug_backtrace()` when duration exceeds the warn threshold. This replaces `SAVEQUERIES` with a targeted mechanism that has near-zero overhead on fast queries.

3. **Retain all existing v1 behavior** — kill detection, admin-search fallback notice, structured logging via Hypercart Logger, tiered context limits.

### Motivation / urgency

We need to deploy the query guard on high-traffic WooCommerce sites where:
- WordPress debug logging can't be enabled (too much output from other plugins)
- 5% sampling misses infrequent slow queries that matter
- 10% CPU overhead from 100% SAVEQUERIES is not acceptable in production
- Hypercart Helper is available for structured logging to `hypercart-logs/`

Enforce mode at 5% sampling is the current workaround (kills are caught at 100%, slow-but-not-killed queries are sampled), but full observation without overhead would be significantly better for diagnosing intermittent issues before enabling enforce.

### References

- Plugin header lines 21-27 (v2 note)
- Issue #12 — mentions "Phase 2 (db.php drop-in)" in context of file extraction
- Issue #11 — per-query filter overhead monitoring (related concern)
- Issue #19 — Phase 2 priority throttling (separate track, but db.php extraction in #12 serves both)

---

## Other Observations from debug.log

The log is mostly noise — repeated warnings that are unrelated to the query guard:

- `_load_textdomain_just_in_time` notices from ACF and Astra (loading translations too early — WP 6.7+ strictness)
- `WP_Dependencies->add_data()` deprecation for IE conditional comments (WP 6.9)
- Dynamic property deprecation in WooCommerce Side Cart Premium (PHP 8.2+)
- Undefined `$footerTxt` variable in the theme's side-cart footer template
