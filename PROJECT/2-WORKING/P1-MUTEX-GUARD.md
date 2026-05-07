# Wave C: Mutex Guard — design doc

**Status:** Design (not implemented)
**Predecessors:** Wave A (load monitor + queue-runner throttle), Wave B (per-action deferral)
**Related:** [ARCHITECTURE.md](../../ARCHITECTURE.md), [README.md](../../README.md)

A planning doc capturing the design intent and decisions for a third Query Guard subsystem that prevents thundering-herd patterns by deduplicating concurrent invocations of the same operation. Implementation follows after this doc lands.

---

## Problem

The plugin's origin failure mode (see [README.md](../../README.md) "Origin") is two copies of the same expensive query running concurrently and saturating the pod. Wave A and Wave B address this on the Action Scheduler side: cap queue-runner throughput under load, defer low-priority actions when MySQL is hot.

But not every thundering herd lives inside Action Scheduler. Concrete examples seen in production:

- **NoFraud `admin_init` scans.** ~150 concurrent admin requests each trigger the same fraud-status reconciliation; all 150 begin the work; the pod goes red. The operation is idempotent — running it once for the cohort would have served all 150 callers correctly.
- **Plugin background syncs hooked to `init`.** Facebook for WooCommerce, Klaviyo, ShipStation: a stuck queue-claim plus normal traffic creates duplicate worker invocations of the same sync function.
- **Cache rebuild storms.** A cache miss on a hot key triggers N concurrent regenerators; the regenerate is expensive; only one needed to run.

Throttling can't help here — the issue isn't aggregate load, it's that the *same operation* started N times in parallel. The fix is a mutex: first caller does the work, subsequent callers either skip or wait.

## Goals

1. Provide a primitive that any plugin code path can use to coalesce concurrent invocations of the same operation across all PHP processes on the host (and across all hosts when the database is shared).
2. Survive the managed-host environment we already target: rotating MySQL connections, per-PHP-worker APCu caches, no shell access for `pt-kill`-style recovery.
3. Hold the same shape as Waves A/B: opt-in, observable, default-safe, and resilient to filter misuse.

## Non-goals

- **Distributed locking with strong correctness guarantees.** No fencing tokens, no quorum, no consensus. This is a "best-effort coalesce" primitive, not a distributed-systems lock manager. If you need true mutual exclusion for financial correctness, this is the wrong tool.
- **Process-liveness detection.** We do not check whether the holder PID is alive; managed hosts make that unreliable. TTL is the recovery mechanism.
- **Queue-style waiting.** `acquire_lock()` returns immediately. Callers that need "wait until available" implement that themselves (and should usually rethink — see "Caller patterns" below).
- **A general-purpose key/value store.** It's a lock primitive. Don't put state in `option_value` beyond what the lock metadata requires.

## Architectural fit

This is a **third independent subsystem**, not a continuation of Wave A/B. Waves A and B both throttle Action Scheduler in response to MySQL load; this subsystem deduplicates concurrent operations regardless of load. Specifically:

- Lives in its own class, `class-hcqg-mutex-guard.php`, following the same file/responsibility split as `HCQG_Load_Monitor` and `HCQG_Priority_Registry`.
- Does not depend on the throttle or its modes — usable independently with `HYPERCART_QUERY_GUARD_THROTTLE_MODE = 'off'`.
- [ARCHITECTURE.md](../../ARCHITECTURE.md) needs updating: the "two independent subsystems" framing becomes three.

## Storage decision

### Where: `wp_options` with `autoload = 'no'`

Decided. Rationale:

- **Cross-process correctness.** Transients fall back to the object cache when one is configured. On managed hosts that present per-PHP-worker APCu (no shared backend), a transient-backed lock is invisible to peer workers — the herd thunders silently. `wp_options` is database-backed, so a write from any PHP worker is visible to all peers immediately.
- **Cross-host correctness.** Multi-server WordPress installs share the database but generally do not share APCu. Same point.
- **No new infrastructure.** `wp_options` exists on every WordPress site. We accept the trade-off (DB writes per acquire) over the alternatives (Redis-required, custom table, AS-backed lock).
- **`autoload = 'no'`** keeps lock rows out of `wp_load_alloptions()`, so they don't pollute the autoload preload that Wave A's earliest hook (init priority 1) is trying to keep small.

### How: atomic acquire, never read-then-write

Decided. The naïve implementation is TOCTOU:

```php
// WRONG — two callers can both pass the read.
if ( get_option( $key ) ) return false;
add_option( $key, ... );
```

Two concurrent acquires can both pass the existence check; the second `add_option` then throws a duplicate-key error which surfaces in PHP logs. Don't write this.

The atomic primitive is a single statement that decides "insert if absent, take if expired" at the row level. **All time comparisons are anchored to PHP's `time()` passed as a parameter — never `UNIX_TIMESTAMP()`** — so clock skew between the web node and the DB node cannot make the effective TTL differ from the requested TTL:

```sql
INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
VALUES (%s, %s, 'no')
ON DUPLICATE KEY UPDATE
    option_value = IF(
        CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) < %d,
        VALUES(option_value),
        option_value
    )
```

`%d` is `time()` from PHP. The `SUBSTRING_INDEX` extracts `expires_at` from the stored value (see "Lock value format" below).

Result interpretation via `$wpdb->rows_affected`:

| `rows_affected` | Meaning |
| ---: | --- |
| 1 | Fresh INSERT — we acquired |
| 2 | UPDATE replaced an expired lock — we acquired |
| 0 | A live unexpired lock is held by someone else — `false` |

(MySQL returns 2 for an UPDATE-via-ON-DUPLICATE-KEY because it counts as both delete and insert internally. This is documented behavior, not a quirk.)

**Document this loudly in the class comment.** Future maintainers will reach for `add_option`/`update_option`; the docblock needs to explain why those are wrong here.

### Option name encoding

`wp_options.option_name` is `varchar(191)` after the utf8mb4 migration. Callers pass arbitrary `$operation_key` strings, so the class hashes internally rather than storing the raw key:

```php
$option_name = 'hcqg_mutex_' . md5( $operation_key );  // 43 chars, fixed
```

The raw `$operation_key` goes into every contention log event so operators investigating a `mutex_held` event see the human-readable key, not the hash.

### Lock value format

Encode `expires_at` and a `holder_nonce` as a delimited string for SQL-portable extraction:

```
1739564821|a4f9c2e8b1d3f607
```

(Integer epoch seconds, `|` separator, 16-char hex nonce from `random_bytes(8)`.)

Why delimited and not JSON: `JSON_EXTRACT()` requires MySQL 5.7+ and behaves inconsistently on older MariaDB 10.x where JSON is a TEXT alias rather than a true type. `SUBSTRING_INDEX` works on every supported version with no edge cases. The JSON shape was cosmetic; the delimited shape is structurally simpler and version-portable. The nonce is hex (`[0-9a-f]+`) so it cannot contain the `|` delimiter.

The nonce is what makes the API safe under TTL overrun:

- Process A acquires at T+0 with nonce `aaa`, TTL 60s.
- Process A's work runs long; lock expires at T+60.
- Process B acquires at T+61 with nonce `bbb`.
- Process A finally calls `release_lock()` at T+90.

Without the nonce, A's release would silently delete B's lock. The CAS predicate for release/refresh extracts the trailing nonce field:

```sql
WHERE option_name = %s
  AND SUBSTRING_INDEX(option_value, '|', -1) = %s
```

A's release is a no-op (nonce mismatch) and B's lock is preserved.

## API spec

```php
HCQG_Mutex_Guard::acquire_lock( string $operation_key, int $ttl = 60 ): bool|string
HCQG_Mutex_Guard::release_lock( string $operation_key, string $holder_nonce ): bool
HCQG_Mutex_Guard::refresh_lock( string $operation_key, string $holder_nonce, int $new_ttl ): bool
HCQG_Mutex_Guard::peek_lock( string $operation_key ): array|false
HCQG_Mutex_Guard::force_release( string $operation_key, string $reason ): bool
```

### `acquire_lock( $key, $ttl = 60 ): bool|string`

Returns the holder nonce (truthy string) on success, `false` if a live lock is held. **Returning the nonce makes release safe** — the caller stores it and passes it to `release_lock()`; without it, release is a no-op.

**Not memoized per-request.** Each call hits the DB independently. This is intentional — per-request memoization would create a same-process reentrancy hole: function A acquires and gets a nonce; function B in the same request "acquires" via the memo without a DB write; A finishes and calls `release_lock`, deleting the row; B is still running and thinks it holds the lock; an external process now wins acquisition; B and the external process run the work concurrently. The mutex is defeated. Each acquire is one bounded `INSERT ... ON DUPLICATE KEY UPDATE` — the write pressure that motivated memoization was a phantom; in steady state under contention, the first caller wins and every peer call (same request or otherwise) gets `affected_rows = 0` and returns false. No thrashing exists to mitigate.

Reentrant semantics in the same request — if any caller actually needs them — are out of scope for Phase 1; reference counting can be added later behind the same API.

### `release_lock( $key, $holder_nonce ): bool`

Conditional delete: only removes the row when the stored nonce matches. Returns `true` if we released our lock, `false` if the row was not ours (TTL overrun + another holder took it) or had already been deleted. Always idempotent.

### `refresh_lock( $key, $holder_nonce, $new_ttl ): bool`

Conditional UPDATE that extends `expires_at` only when the stored nonce matches. Returns `true` on success, `false` if we no longer hold the lock. **Required for any operation that legitimately runs longer than the initial TTL** — a 4-minute import shouldn't take a 5-minute TTL and hope. Hold a 30s TTL and refresh every 15s instead.

### `peek_lock( $key ): array|false`

Returns the lock row (decoded `['expires_at' => …, 'holder' => …]`) without acquiring, or `false` if no live lock. **Monitoring/UI only.** The result is stale by the time the caller reads it; do not branch control flow on `peek_lock`. The docblock must say this loudly. (Renamed from the original proposal's `is_locked` to make the read-only intent unmistakable.)

### `force_release( $key, $reason ): bool`

Unconditional delete, logged. For admin/CLI recovery when a stuck lock can't be released by its holder (process died, holder code lost the nonce, etc.). Emits a structured `mutex_force_released` log entry with the prior holder's nonce, the calling user, the supplied reason string, and a timestamp. Returns `true` if a row was deleted.

## Caller patterns

What to do when `acquire_lock()` returns `false`:

| Pattern | Verdict | When |
| --- | --- | --- |
| Skip the work, serve a cached / stale result | ✅ Default | Idempotent reconciliations (NoFraud-style fraud-status sync, cache regeneration where stale data is acceptable) |
| Schedule via Action Scheduler for later | ⚠️ Composes well | Work that must eventually run but doesn't have to run *now*. Pairs naturally with Wave B's deferral. |
| Busy-wait / poll-and-retry within the same request | ❌ Don't | Spin loops make the herd worse, exhaust PHP-FPM workers, and replicate exactly the failure mode the lock was meant to prevent. |

The README and the class docblock both need this table. Without explicit guidance, users will write pattern 3 because it "feels safe."

## Logging

Default: contention events only. Successful acquires in steady state are silent.

| Event | Level | When |
| --- | --- | --- |
| `mutex_held` | info | `acquire_lock` returned `false` because a live lock was held |
| `mutex_force_released` | warn | `force_release` was invoked; payload includes prior nonce, reason, calling user |
| `mutex_release_skipped` | info | `release_lock` was a no-op because the nonce didn't match (TTL overrun + replacement) |
| `mutex_refresh_failed` | info | `refresh_lock` was a no-op because we no longer held the lock |

A `HCQG_MUTEX_DEBUG` constant or filter enables verbose tracing (`mutex_acquired`, `mutex_released`) for dev / incident investigation. Off by default — Wave B taught us that per-event logging without dedup creates incident noise rather than signal.

## Phase 1 — MVP

Ship the smallest thing that solves the NoFraud `admin_init` thundering-herd, validates the storage decisions in production, and proves the API shape before we extend it.

**Scope: short-TTL, single production caller.** No `refresh_lock`, no convenience wrappers, no GC task — every deferred item is justified below in Phase 2.

### Implementation

- [ ] Create `class-hcqg-mutex-guard.php` (top-level alongside the other `class-hcqg-*.php` files).
- [ ] Implement `HCQG_Mutex_Guard::acquire_lock( $key, $ttl = 60 ): string|false` with atomic `INSERT ... ON DUPLICATE KEY UPDATE` and `rows_affected` interpretation per the storage section. Time comparisons in SQL must use `time()` passed as a `%d` parameter — never `UNIX_TIMESTAMP()` — to anchor TTL to the web node and avoid clock-skew drift.
- [ ] Implement `HCQG_Mutex_Guard::release_lock( $key, $holder_nonce ): bool` as CAS-conditional delete on `SUBSTRING_INDEX(option_value, '|', -1) = nonce`.
- [ ] Implement `HCQG_Mutex_Guard::peek_lock( $key ): array|false` (read-only, monitoring-only docblock with `@internal`-style warning about TOCTOU).
- [ ] Implement `HCQG_Mutex_Guard::force_release( $key, $reason ): bool` with structured logging.
- [ ] Internal `option_name` encoding: `'hcqg_mutex_' . md5( $key )` to bound storage at 43 chars regardless of caller-supplied key length. Pass the raw key through to log payloads so operators see human-readable keys in `mutex_held` events.
- [ ] Lock value format: `expires_at|nonce` delimited string (epoch seconds + `|` + 16-char hex nonce). Documented in the class header alongside the SQL extraction patterns.
- [ ] Holder nonce: 16-char hex from `random_bytes(8)` with a `mt_rand` fallback (matches how the rest of the plugin handles entropy edge cases).
- [ ] Contention-only logging by default: `mutex_held`, `mutex_force_released`, `mutex_release_skipped`. No `mutex_acquired` records in steady state.
- [ ] Loud class-level docblock explaining (a) why `add_option`/`update_option` are wrong here, (b) why the raw `$wpdb` SQL is intentional, and (c) why time anchoring uses `time()` not `UNIX_TIMESTAMP()`.

### Decisions to lock in before coding

- [ ] Class/file name: `HCQG_Mutex_Guard` vs `HCQG_Coalesce_Guard` vs `HCQG_Singleton_Guard`. (See Open questions.)
- [ ] Default TTL: confirm 60s or pick a different number based on observed NoFraud scan duration.

### Tests (unit, against the existing stubbed bootstrap)

- [ ] `acquire_lock` calls into the SQL layer on every invocation (no memoization) — verified via a `$wpdb` mock query counter that increments for each call.
- [ ] `release_lock` returns `false` and is a no-op when the nonce doesn't match.
- [ ] `peek_lock` returns the decoded structure when a row exists, `false` when none.
- [ ] `force_release` deletes regardless of nonce and emits a `mutex_force_released` log event with the supplied reason.
- [ ] Lock value (de)serialization round-trips correctly (epoch + nonce hex through `expires_at|nonce`).
- [ ] Option-name encoding produces a 43-char `hcqg_mutex_*` form regardless of input length, including unicode and edge-case keys.
- [ ] All time-related SQL parameters carry `time()` from PHP, never `UNIX_TIMESTAMP()` (regex/string assertion against the rendered query).

The actual SQL atomicity (the `ON DUPLICATE KEY UPDATE` expired-takeover branch, `rows_affected = 2` semantics) is **not unit-tested in Phase 1** — it gets validated on staging during rollout. This gap is explicit, not accidental; see "Testing strategy" above.

### Documentation

- [ ] Add a `class-hcqg-mutex-guard.php` row to [ARCHITECTURE.md](../../ARCHITECTURE.md)'s file layout.
- [ ] Replace ARCHITECTURE.md's "two independent subsystems" framing with three.
- [ ] Add a "Mutex Guard" subsystem section to ARCHITECTURE.md with a state-model row for `wp_options` lock storage.
- [ ] Add a "Mutex Guard" section to README.md with the API summary and the Caller-patterns table from this doc.
- [ ] CHANGELOG entry under `[Unreleased]`.

### Rollout

- [ ] Identify the NoFraud `admin_init` scan call site; wrap it with `acquire_lock` / `release_lock`.
- [ ] Stage on a non-production environment, simulate concurrent admin requests, confirm one acquire wins per cohort.
- [ ] Deploy to one production store with monitoring on `mutex_held` event volume and `wp_options` lock-row count.
- [ ] Watch for one week before extending to additional callers (Phase 2).

---

## Phase 2 — Extensions

Only after Phase 1 has been in production long enough to prove the storage and API decisions. Each item is here because it adds capability that Phase 1 deliberately omits, not because it was forgotten.

### Long-running operations

- [ ] Implement `HCQG_Mutex_Guard::refresh_lock( $key, $holder_nonce, $new_ttl ): bool` as CAS-conditional UPDATE on nonce match.
- [ ] Add unit test: `refresh_lock` returns `false` when the nonce doesn't match.
- [ ] Document the "hold a 30s TTL, refresh every 15s" pattern in README and ARCHITECTURE.

Reason for deferral: the only Phase 1 caller (NoFraud `admin_init`) finishes inside a short TTL. Real long-running callers can't appear before Phase 1 ships.

### Ergonomics

- [ ] Add `HCQG_Mutex_Guard::with_lock( $key, $ttl, callable $work ): bool` that acquires, runs the callable, and guarantees release in a `finally`. Returns `true` if the work ran, `false` if the lock was held.
- [ ] Update README to recommend `with_lock` over manual acquire/release for new callers.

Reason for deferral: ergonomic helper, not a primitive. Easy to add once the four primitives stabilize; risky to ship before we've seen the primitives used in real code.

### Expanded callers

- [ ] Audit additional thundering-herd vectors (FB sync, Klaviyo sync, ShipStation sync, cache regeneration) for mutex candidates.
- [ ] Wrap each accepted candidate; add to integration test plan.

### Operational hardening (gated on real evidence)

- [ ] If lock-row accumulation is observed in production after Phase 1, add a daily AS task that deletes expired-and-orphaned rows from `wp_options` where `option_name LIKE 'hcqg_mutex_%'` AND the encoded `expires_at` is older than 24h.
- [ ] If callers are observed implementing ad-hoc retry loops, add a `hypercart_query_guard_mutex_retry_policy` filter and a documented retry helper.
- [ ] If `mutex_held` log volume becomes problematic, add per-(key, request) dedup similar to Wave B's observe-mode dedup. **More likely to be needed than originally estimated**: without per-request memoization, every contended caller in the same request now produces a separate `mutex_held` event. Watch this on the first NoFraud rollout.

Reason for deferral: each is a fix for a problem we don't know exists yet. Without memoization, lock-row counts are still bounded by the number of distinct keys (not by request rate), so accumulation should remain manageable — but verify on rollout rather than pre-engineering.

## Testing strategy

This is the first subsystem whose contract is genuinely SQL-shaped, not WP-API-shaped. The Wave A/B test bootstrap stubs the WP function layer (`apply_filters`, `wp_cache_*`, `get_option`) but not `$wpdb`. Three options:

| Option | Cost | Coverage |
| --- | --- | --- |
| Add `$wpdb` mocks to the bootstrap | Medium | Public API + happy-path SQL paths + query-shape assertions in unit tests; can't validate actual MySQL semantics (e.g. `rows_affected` returning 2 for ON DUPLICATE KEY UPDATE) |
| Stand up a SQLite-backed wpdb shim | High | Closer to real, but SQLite doesn't support `ON DUPLICATE KEY UPDATE` — would need rewriting for tests, defeating the purpose |
| Integration tests against staging MySQL only | Low | Full SQL validity, but slow and host-dependent |

**Recommendation for v1:** option 1. Cover with unit tests:
- `acquire_lock` issues one DB call per invocation (no memoization).
- `release_lock` returns `false` when nonce doesn't match.
- `peek_lock` returns decoded structure or `false`.
- `force_release` logs structured event.
- Lock-value (de)serialization round-trips through `expires_at|nonce`.
- Option-name encoding bounds at 43 chars regardless of input.
- Rendered SQL contains a `time()` parameter, never `UNIX_TIMESTAMP()`.

The actual SQL — `ON DUPLICATE KEY UPDATE`'s atomicity, `rows_affected` semantics, the expired-takeover branch — gets validated through staging integration tests during rollout, not unit tests. Document this gap explicitly.

## Open questions

- **Naming.** `HCQG_Mutex_Guard` is functional but reads awkwardly alongside `Hypercart_Query_Guard`. Alternatives considered: `HCQG_Coalesce_Guard` (describes the pattern: deduplicate concurrent invocations), `HCQG_Singleton_Guard` (describes the runtime guarantee). Pick one before implementation; renaming after callers adopt is annoying.
- **Default TTL.** Proposed 60s. Worth checking against actual NoFraud / FB sync runtimes before locking in — too short means TTL overrun is the common case; too long means dead-process recovery is slow.
- **Should `with_lock( $key, $ttl, $callable )` join Phase 1 instead of Phase 2?** With per-request memoization removed, the "forgot to call `release_lock`" footgun is more consequential — manual `acquire`/`release` callers carry slightly more responsibility than they did under the original design. `with_lock` eliminates the class of bug entirely. Lean toward including it in Phase 1 unless it adds material implementation cost.

## Sequencing

1. Approve this doc (or push back on specific decisions above).
2. Pick the naming.
3. Implement `class-hcqg-mutex-guard.php` + tests in a Wave C branch off main.
4. Update ARCHITECTURE.md and README.md as part of the implementation PR.
5. Roll out to one production caller (NoFraud `admin_init` is the obvious first customer) before extending.
