# Issue #31 — Proposed expanded body

**Proposed title change:** `V2 update` → `v2: db.php drop-in — early-query coverage + zero-overhead observation`
(Optional — current title is fine, just vague.)

---

## Problem

Two independent v1 limitations share a single root cause: the plugin hooks `init` at priority 1 and relies on WordPress core's `SAVEQUERIES` constant. A `wp-content/db.php` drop-in resolves both.

### 1. Early-query coverage gap

v1 hooks `init` at priority 1, so queries fired before then run without a `MAX_EXECUTION_TIME` ceiling:

- `wp_load_alloptions()` (autoloaded options)
- Auth / usermeta lookups
- WC session bootstrap
- Anything on `muplugins_loaded`, `plugins_loaded`, `setup_theme`

On sites where these early queries are the ones that go runaway (bloated autoload table, cold object-cache miss, slow `alloptions` on a managed-host MySQL hiccup), v1 enforce mode provides no protection. Documented in `hypercart-query-guard.php` header lines 21–27.

**Signs you need v2** (Noel's framing from the original issue):

- kills logged with `context: frontend` and a `last_query` against `wp_options`
- repeated `alloptions` slow-query warnings in observe mode
- any kill whose stack trace points into `plugins_loaded`

### 2. SAVEQUERIES observation overhead

WordPress core's `SAVEQUERIES` calls `debug_backtrace()` on **every query** unconditionally — before checking duration, before anything. The order inside `wpdb::query()`:

```
1. Start timer
2. Execute query              ← duration unknown
3. Stop timer                 ← duration NOW known
4. debug_backtrace()          ← expensive, runs unconditionally
5. Store query + duration + backtrace in $wpdb->queries
```

The plugin's shutdown handler then walks `$wpdb->queries` and only logs queries over the warn threshold (5s). But by that point, the cost has already been paid on every query.

**Measured cost on a WooCommerce page** (~300 queries, ~300ms PHP CPU time, ~60MB memory — from `query-guard-findings.md`):

| Cost | Estimate | As % of normal work |
|------|----------|---------------------|
| Extra CPU | ~30ms (300 × ~0.1ms per backtrace) | **~10%** |
| Extra memory | ~1MB (SQL strings + backtrace strings) | ~1.5% |

**Site-wide CPU overhead scales linearly with sample rate:**

| Sample rate | Site-wide CPU overhead |
|-------------|----------------------|
| 5% (v1 default in observe mode) | ~0.5% |
| 25% | ~2.5% |
| 100% (v1 enforce mode) | ~10% |

This ~10% at 100% sampling is what's blocking the deployment plan: **observer mode at 100% prior to enforce mode at 100%** is the safe rollout sequence on busy production sites, but the SAVEQUERIES cost makes 100% observation unaffordable.

The irony: query duration is **already known at step 3** before the backtrace runs at step 4. Core just doesn't check it. A conditional backtrace — time every query cheaply, only backtrace the slow ones — would reduce observation overhead to near zero. But there is no hook between "duration known" and "backtrace called", and SAVEQUERIES is a blunt on/off switch.

## Solution

A `wp-content/db.php` drop-in that extends `wpdb` and overrides the query path to:

### 1. Apply `SET SESSION MAX_EXECUTION_TIME` at connection time

Inside `wpdb::db_connect()` (or a first-query gate), so the limit is in place before `wp_load_alloptions()` and the rest of the pre-`init` query traffic. No hook dependency.

### 2. Implement conditional backtracing

Time every query (microsecond cost — `wpdb` does this anyway). Only call `debug_backtrace()` when `duration >= warn_threshold`. This replaces SAVEQUERIES with a targeted mechanism that has near-zero overhead on fast queries.

### 3. Retain all v1 behavior

- Modes (`off` / `observe` / `enforce`)
- Tiered context limits (CLI / AS / Cron / AJAX / REST / Frontend / Admin / Checkout)
- Kill detection via `$wpdb->last_error`
- Admin-search timeout fallback notice
- Structured logging via `Hypercart_Logger` (with `error_log()` fallback)
- Re-application on connection rotation

## Acceptance criteria

- [ ] Measured CPU overhead of 100% observation drops from ~10% to <1% on a representative WooCommerce request. Before/after numbers captured in a benchmark commit.
- [ ] Pre-`init` queries (`wp_load_alloptions()`, auth/usermeta, WC session) are covered by `MAX_EXECUTION_TIME` in `enforce` mode — verified by killing an artificially slow autoload-option query in staging.
- [ ] Behavior parity with v1: same log event names, same payload structure, same admin-search fallback, same tiered limits. Existing tests pass; new tests added for the drop-in's conditional-backtrace logic and first-query gating.
- [ ] Safe activation / deactivation. `db.php` is a drop-in, not a plugin — it persists when the mu-plugin is removed, and the mu-plugin can be removed without breaking the site. Define the contract for both directions:
  - mu-plugin removed, db.php remains → drop-in degrades gracefully (no fatal, no leftover `SET SESSION`)
  - db.php removed, mu-plugin remains → v1 behavior resumes from `init` onward
- [ ] Documented rollback path. A site admin needs a one-step way to disable the drop-in without losing v1 protection.

## Rollout question (needs a decision before implementation)

Two viable packaging models:

**Option A — Ship inside this plugin, installer copies/symlinks to `wp-content/db.php`**
- Pro: single repo, single version, mu-plugin and drop-in are guaranteed to be in sync.
- Con: requires an installer step (composer post-install, WP-CLI helper, or manual instruction). Drop-in deployment is sticky — uninstalling the mu-plugin leaves the drop-in unless the installer handles teardown.

**Option B — Ship as a separate companion package**
- Pro: clean separation, drop-in lifecycle decoupled from mu-plugin.
- Con: two repos to version in lockstep, two deployment steps, easier to end up with mismatched versions in production.

Recommendation pending — leaning toward Option A with a `wp queryguard install-dropin` / `wp queryguard remove-dropin` WP-CLI pair to manage the symlink/copy and detect drift.

## Out of scope (handled elsewhere or punted)

- Admin UI for v2 settings — tracked under #19 (Phase 2)
- WP-CLI surface beyond the drop-in installer — tracked under #19
- Throttle subsystem changes — independent track (#3, #17)

## References

- `hypercart-query-guard.php` header lines 21–27 (original v2 note)
- `query-guard-findings.md` lines 122–226 (Norman's Nursery field report — SAVEQUERIES overhead analysis, proposed solution, motivation)
- #11 — per-query filter overhead monitoring (related concern)
- #12 — Phase 2 throttle engine extraction (mentions "Phase 2 (db.php drop-in)" in passing)
- #19 — Phase 2 umbrella (admin UI + WP-CLI alongside the drop-in)
