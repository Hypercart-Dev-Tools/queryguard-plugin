# Hypercart Query Guard

A PHP-side circuit breaker for WordPress that enforces MySQL `MAX_EXECUTION_TIME` on read queries to prevent runaway `SELECT`s from saturating a managed-hosting pod.

Built for high-volume WooCommerce stores on managed hosts (WP Engine, Pressable, Kinsta) where you don't have access to `pt-kill` or shell-level MySQL controls. Solves the failure mode where a single bad admin search, a stuck background sync, or an unindexed plugin query takes down the entire site by exhausting CPU and PHP-FPM workers.

## What it does

- Sets `MAX_EXECUTION_TIME` per MySQL session at the start of each request, so MySQL itself kills any `SELECT` exceeding the configured ceiling (default 30 seconds, tiered by request context).
- Detects connection rotation (managed hosts pool/rotate MySQL connections) and re-applies the session limit automatically — no heartbeat needed.
- Logs killed queries with their URI, calling user, and context for post-incident analysis.
- Surfaces a recovery notice in `wp-admin` when an admin search is killed, so the user sees "Search timed out, try a more specific query" instead of a misleading "no results found" (which causes them to retry and re-trigger the runaway).
- Ships a SAVEQUERIES-based observe mode for safe rollout: identifies queries that *would* be killed before you turn enforcement on.

## What it does not do

- Does **not** kill `UPDATE`, `DELETE`, or `INSERT`. `MAX_EXECUTION_TIME` is read-only by MySQL design, and that's intentional — killing a write mid-transaction can corrupt order state, Action Scheduler claims, or HPOS sync tables.
- Does not address lock contention (queries waiting on row locks rather than executing). For that you need `innodb_lock_wait_timeout`, which is a server-level setting on most managed hosts.
- Does not rate-limit query *volume*. A flood of fast queries can still saturate CPU; this plugin only catches individually slow queries.

## Installation

Drop the file into `wp-content/mu-plugins/`:

```
wp-content/mu-plugins/hypercart-query-guard.php
```

No activation step. MU-plugins load automatically.

## Configuration

Add to `wp-config.php`:

```php
define( 'HYPERCART_QUERY_GUARD_MODE', 'observe' );
```

Modes:

- **`off`** — plugin loads but does nothing. Use to disable without uninstalling.
- **`observe`** *(default)* — no `MAX_EXECUTION_TIME` set, no kills. Samples 5% of requests, logs queries that exceed 5 seconds. Safe to run in production for a week before flipping to enforce.
- **`enforce`** — applies the tiered ceiling, kills runaway queries, logs every kill, and shows the admin recovery notice.

## Tiered limits

Limits are applied per request context. Override via the `hypercart_query_guard_limit_ms` filter:

| Context              | Default ceiling | Rationale                                         |
| -------------------- | --------------- | ------------------------------------------------- |
| WP-CLI               | unlimited       | Long-running migrations, imports                  |
| Action Scheduler     | unlimited       | Background workers, writes anyway                 |
| `wp-cron.php`        | 10 seconds      | Cron should never be slow; cheap signal           |
| `admin-ajax.php`     | 20 seconds      | Most spike vectors live here (FB sync, NoFraud)   |
| REST API             | 30 seconds      | Klaviyo polling, WC REST `/orders`                |
| Frontend (default)   | 30 seconds      | Product pages, my-account                         |
| `wp-admin`           | 45 seconds      | Admin search/filter operations tolerated longer   |
| Checkout             | 60 seconds      | Coupon validation, fraud checks, shipping calc    |

Filter example:

```php
add_filter( 'hypercart_query_guard_limit_ms', function( $ms, $context ) {
    if ( $context === 'rest_api' && strpos( $_SERVER['REQUEST_URI'], 'klaviyo' ) !== false ) {
        return 15000; // tighter ceiling for Klaviyo specifically
    }
    return $ms;
}, 10, 2 );
```

## Logging

If `Hypercart_Logger` (from the Hypercart Performance Monitor plugin) is present, log lines route through it. Otherwise the plugin falls back to `error_log()` with single-line JSON for `grep`-ability:

```
[hypercart_query_guard][error] {"event":"query_killed","context":"admin_ajax","limit_ms":20000,"last_query":"SELECT * FROM wp_nf_transactions WHERE meta_key = '_nofraud_transaction_status_workaround'","uri":"/wp-admin/admin-ajax.php","user_id":0,"time":1745875234}
```

Two event types:

- **`slow_query`** *(warn)* — query exceeded 5s but completed; sampled in observe mode, always in enforce mode.
- **`query_killed`** *(error)* — MySQL killed the query for hitting the limit; only emitted in enforce mode.

## Limitations and caveats

- **`init` priority 1 is not the earliest possible hook — early-boot queries are unprotected.** The `SET SESSION` is applied on `init` priority 1, so anything that queries the database before then runs without the ceiling. In practice the unprotected window contains:
  - The autoloaded options preload (`wp_load_alloptions()`) — a single `SELECT … FROM wp_options WHERE autoload = 'yes'`. Slow only on sites with bloated `wp_options` (10k+ autoloaded rows from plugins that never clean up).
  - User and usermeta lookups during auth (`determine_current_user`, `wp_validate_auth_cookie`).
  - WooCommerce session bootstrap on `plugins_loaded` priority 10 (`WC_Session_Handler::init`), which can hit `wp_woocommerce_sessions` and `wp_actionscheduler_*`.
  - Anything plugins do on `muplugins_loaded`, `plugins_loaded`, or `setup_theme` — including object-cache warmup queries.

  The v2 path that closes this gap is a `wp-content/db.php` drop-in that sets `MAX_EXECUTION_TIME` at connection time (inside `wpdb::db_connect()`), before the first query is possible. v1 covers everything from `init` onward, which empirically catches the failure modes this plugin was built for (admin search, REST polling, AJAX vectors, background sync). Signs you need v2: kills logged with `context: frontend` and a `last_query` against `wp_options`, repeated alloptions slow-query warnings in observe mode, or any kill whose stack trace points into `plugins_loaded`.
- **`SAVEQUERIES` has memory cost.** Observe mode samples 5% of requests by default to keep overhead bounded. Don't run observe at 100% sampling on a high-traffic site.
- **WP Engine reconnects.** WPE's MySQL proxy occasionally rotates connections mid-request. The static `$last_dbh` identity check detects this and re-applies the limit automatically.
- **Older MySQL.** `MAX_EXECUTION_TIME` requires MySQL 5.7.8+ or Percona/MariaDB equivalents. The plugin suppresses errors on the `SET SESSION` itself, so an unsupported server fails open (no protection, no breakage).

## Origin

Built in response to a CPU-saturation incident on a high-volume WooCommerce store where a single Facebook background sync query (`SELECT … FROM wp_comments WHERE comment_ID IN (… 10,010 items …)`) running concurrently with itself took down the whole pod. The kill switch is the cheapest insurance against that class of failure: a few hours of work, indefinite payoff.

## License

This plugin is licensed under the **GNU General Public License v2.0 or later** (GPL-2.0-or-later), the same license as WordPress itself.

You are free to use, modify, and redistribute this plugin under the terms of the GPL. There is no warranty; see the license text for details.

```
Hypercart Query Guard
Copyright (C) 2026 Neochrome, Inc. (Hypercart)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, see <https://www.gnu.org/licenses/gpl-2.0.html>.
```

Full license text: <https://www.gnu.org/licenses/gpl-2.0.html>
