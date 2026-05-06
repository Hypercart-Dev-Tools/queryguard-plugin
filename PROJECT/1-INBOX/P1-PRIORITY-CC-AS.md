# P1: Priority-Based Concurrency Control for Action Scheduler

## Context

Issue: `Hypercart-Dev-Tools/queryguard-plugin#3`

Current plugin scope:

- Single-file MU plugin: `hypercart-query-guard.php`
- Primary protection today: per-request MySQL `MAX_EXECUTION_TIME` on read queries
- Existing value: kills individually runaway `SELECT`s and logs slow/killed queries

Incident gap:

- QueryGuard handles query duration, not queue pressure
- The May 1 failure mode was concurrency-driven: many moderate queries running at once
- Action Scheduler work from lower-value integrations can compete with payment and order-critical jobs during load spikes

## Problem Statement

We need a second layer of defense that reacts to database pressure before saturation becomes user-visible.

Target outcome:

- Critical background work keeps flowing under load
- Deferrable integration syncs back off automatically
- Query volume and concurrency are reduced before QueryGuard has to kill work
- No actions are lost; lower-priority work is deferred, not dropped

## Product Goal

Add a priority-aware throttle system for Action Scheduler that:

- detects site load using cheap, host-compatible signals
- reduces Action Scheduler throughput under pressure
- defers lower-priority actions by hook pattern
- exposes decisions through structured logs
- remains safe on managed WordPress hosts

## Non-Goals for P1

- No admin UI
- No WP-CLI command surface
- No large class/file architecture refactor unless needed for clarity
- No attempt to throttle arbitrary plugin code outside Action Scheduler
- No dependency on server-level tooling or shell access

## Design Principles

1. Prefer host-safe signals over ideal-but-fragile signals.
2. Fail safe, not fail open, but avoid making the detector itself a load source.
3. Ship control-plane throttling first; ship per-action deferral only after the basics are stable.
4. Keep the first version compatible with the plugin's current MU-plugin deployment model.
5. Use logging as the main observability layer before adding UI.

## Recommended Scope Split

### Phase 1: Load-Aware Queue Throttling

Deliver a minimal, low-risk throttle that changes queue runner behavior globally under load.

Features:

- Load monitor with cached classification: `normal`, `elevated`, `critical`
- Action Scheduler filters:
  - `action_scheduler_queue_runner_batch_size`
  - `action_scheduler_queue_runner_time_limit`
  - `action_scheduler_queue_runner_concurrent_batches`
- Structured logs for:
  - load-level transitions
  - throttling decisions

Behavior:

| Load | Batch Size | Time Limit | Concurrent Batches |
| --- | ---: | ---: | ---: |
| Normal | default | default | default |
| Elevated | 5 | 15s | 1 |
| Critical | 1 | 10s | 1 |

Why this first:

- Lowest behavioral risk
- No action mutation/rescheduling yet
- Immediately reduces concurrency pressure from Action Scheduler workers
- Maps cleanly to Action Scheduler's documented tuning hooks

### Phase 2: Priority-Based Deferral

Once Phase 1 is stable, add per-hook priority routing and deferred rescheduling.

Features:

- Hook-pattern priority registry
- Decision matrix by load level and action priority
- Deferred actions are rescheduled into the future, never deleted
- Logs record hook, priority, load, and delay applied

Suggested policy:

| Load | Critical | High | Normal | Deferrable |
| --- | --- | --- | --- | --- |
| Normal | Run | Run | Run | Run |
| Elevated | Run | Run | Delay 5m | Delay 15m |
| Critical | Run | Delay 5m | Delay 15m | Delay 60m |

Why second:

- Higher correctness risk
- Must be validated carefully against Action Scheduler action lifecycle behavior
- Requires strong safeguards against duplicate work and endless deferral loops

### Phase 3: Mutex Guard

Add an opt-in lock primitive for known thundering-herd patterns outside or alongside Action Scheduler.

Use cases:

- Expensive scans triggered repeatedly by concurrent requests
- Plugin code that does not already dedupe work

Implementation requirement:

- Lock acquisition must be atomic
- Prefer `add_option()`-based creation semantics over read-then-write
- Store lock timestamp and TTL using `autoload = no`

Why separate:

- Solves a related but different class of problem
- Can be adopted selectively in affected integrations

## Architecture Recommendation

Do not implement the full issue comment architecture in one pass.

The issue comment proposes:

- `class-load-monitor.php`
- `class-priority-registry.php`
- `class-throttle-engine.php`
- `class-mutex-guard.php`
- admin settings
- CLI commands

That is reasonable as a long-term shape, but it is too large for the current repo state. The plugin is still one file, with no existing admin or bootstrap structure.

Recommended repo evolution:

### Step A

Keep P1 in the main file if the code stays small enough to review comfortably.

### Step B

Extract only if needed:

- `class-hcqg-load-monitor.php`
- `class-hcqg-throttle-engine.php`

### Step C

Add registry and mutex modules only when their behavior is being shipped.

## Technical Plan

## 1. Load Detection

Signals, in order of preference:

1. MySQL `Threads_running` via `SHOW STATUS LIKE 'Threads_running'`
2. Action Scheduler queue depth as fallback

Rules:

- Cache the computed load level for 5-10 seconds
- If `Threads_running` is unavailable, continue with queue-depth-only mode
- If both signals fail, classify as `elevated`
- Log transitions only when the level changes

Default thresholds:

```php
array(
	'threads_running_elevated' => 5,
	'threads_running_critical' => 15,
	'queue_depth_elevated'     => 100,
	'queue_depth_critical'     => 500,
)
```

Important caution:

- Queue depth counting can become its own expensive query on busy stores
- Use a short cache and avoid repeated counting inside the same request

## 2. Action Scheduler Integration

Implement Phase 1 with official hooks:

```php
add_filter( 'action_scheduler_queue_runner_batch_size', ... );
add_filter( 'action_scheduler_queue_runner_time_limit', ... );
add_filter( 'action_scheduler_queue_runner_concurrent_batches', ... );
```

Implementation notes:

- Only apply these filters when Action Scheduler is present
- Do not assume a specific bundled version unless the hook exists
- Log the effective decisions once per request, not on every callback invocation

## 3. Priority Registry

For P2, use simple pattern-based matching:

- `critical`
- `high`
- `normal`
- `deferrable`

Default registry:

```php
array(
	'critical' => array(
		'nofraud_*',
		'woocommerce_payment_*',
		'wc_payment_*',
	),
	'high' => array(
		'woocommerce_scheduled_subscription_*',
		'wcs_*',
		'woocommerce_deliver_webhook_*',
	),
	'normal' => array(
		'woocommerce_run_*',
		'action_scheduler_*',
	),
	'deferrable' => array(
		'facebook_for_woocommerce_*',
		'wc_facebook_*',
		'shipstation_*',
		'klaviyo_*',
		'woocommerce_flush_*',
	),
)
```

Recommendation:

- Store defaults in code first
- Add filter overrides before adding options UI

That keeps rollout simple and reviewable.

## 4. Deferral Mechanics

This is the highest-risk part of the feature.

Before implementing, verify exactly how the running Action Scheduler version behaves when:

- an action is inspected before execution
- a callback throws
- an action is rescheduled from inside execution hooks
- a claimed action is released or retried

Safer implementation preference:

- Decide deferral before expensive callback work begins
- Reschedule using Action Scheduler APIs
- Mark the current execution path as intentionally skipped in a way that does not create duplicate future actions

Open risk:

- `action_scheduler_before_execute` may not be the safest place to mutate scheduling state without confirming store behavior

This part should be prototyped and manually validated before merge.

## 5. Mutex Guard

Implement as a small utility with:

- `acquire_lock( $key, $ttl )`
- `release_lock( $key )`
- `is_locked( $key )`
- `force_release( $key )`

Requirements:

- Atomic lock create path
- Expiry based on stored acquisition timestamp plus TTL
- No autoloaded options

Suggested naming:

- Option key prefix: `_hcqg_mutex_`

## Logging Plan

New event types:

- `load_level_transition`
- `as_throttle_applied`
- `as_action_deferred`
- `mutex_acquired`
- `mutex_released`
- `mutex_acquire_failed`

Payload fields should include:

- timestamp
- load level
- detector inputs used
- action hook when relevant
- priority when relevant
- delay when relevant
- request context / URI where safe

## Configuration Strategy

For P1, prefer code-level filters over UI:

- `hypercart_query_guard_throttle_enabled`
- `hypercart_query_guard_load_thresholds`
- `hypercart_query_guard_action_priorities`
- `hypercart_query_guard_throttle_policy`

Reason:

- Faster to ship
- Easier to review
- Lower maintenance burden than an early settings screen

## Testing Plan

### Unit-Level

- load classification from thresholds
- wildcard hook matching
- throttle decision matrix
- mutex expiry and acquisition behavior

### Integration-Level

- when load is `elevated`, batch size/time limit/concurrency are reduced
- when load returns to `normal`, defaults are restored
- deferrable hooks are delayed under `critical`
- critical hooks still run under `critical`

### Manual Staging

- simulate high pending queue depth
- run mixed hooks across priority tiers
- confirm subscriptions/payment-related hooks continue
- confirm Facebook/ShipStation/Klaviyo-style hooks back off
- confirm no duplicate reschedules are created

## Acceptance Criteria

Phase 1 is done when:

- Action Scheduler throughput is reduced automatically under pressure
- the detector uses caching and does not become a hotspot
- throttle decisions are visible in logs
- normal-load behavior remains unchanged

Phase 2 is done when:

- lower-priority actions are deferred rather than dropped
- critical hooks continue under pressure
- no duplicate or orphaned actions are created

Phase 3 is done when:

- known herd-prone operations can be protected by a shared lock
- expired locks self-heal

## Risks

- `SHOW STATUS` may be blocked by host policy
- queue-depth queries may add measurable load on large stores
- Action Scheduler lifecycle hooks may not support naive rescheduling safely
- an over-aggressive detector could unnecessarily delay business-critical work
- a poor default registry could misclassify hooks on real stores

## Recommendation

Ship this as a staged feature, not a single large implementation.

Recommended next milestone:

1. Implement Phase 1 only
2. Document hooks and filters
3. Test on staging against a real queue-heavy store
4. Add Phase 2 only after lifecycle behavior is validated

That gets the main benefit quickly while keeping the failure surface small.
