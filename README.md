# Liquid Monitor connector for Laravel

Laravel counterpart to [`liquid-monitor-connector`](https://github.com/liquiddesign/liquid-monitor-connector)
(the Nette connector). Implements the same HTTP contract against the
[Liquid Monitor](https://github.com/liquiddesign/liquid-monitor-back) backend — cron/job
lifecycle, error reporting, a read-only DB query proxy and a log-viewer proxy — so the
backend needs no changes to support Laravel-hosted projects.

## Installation

```bash
composer require liquiddesign/liquid-monitor-connector-laravel
php artisan vendor:publish --tag=liquid-monitor-config
```

Set the base config in `.env`:

```dotenv
LIQUID_MONITOR_URL=https://monitor.example/api
LIQUID_MONITOR_API_KEY=your-project-api-key
```

`LIQUID_MONITOR_URL` is the monitor's base API URL — **without** a `/connector` or
`/front` suffix, those are appended per endpoint. The cron and log channels can point at
a different monitor instance via `LIQUID_MONITOR_CRON_URL`/`LIQUID_MONITOR_CRON_API_KEY`
and `LIQUID_MONITOR_LOG_URL`/`LIQUID_MONITOR_LOG_API_KEY`; unset, they fall back to the
top-level URL/key.

## Cron / job lifecycle

Register a cron in a service provider's `boot()`:

```php
use LiquidMonitorConnector\Facades\LiquidMonitor;
use LiquidMonitorConnector\Jobs\JobContext;

LiquidMonitor::registerCron('daily-report', function (JobContext $ctx) {
    $ctx->progress(['step' => 'fetching data']);

    // ... business logic ...
}, [
    'name' => 'Daily report',
    'repeatCount' => 2,
    'canRunConcurrently' => false,
]);
```

Point your own scheduler (or a real system cron entry) and the monitor at the same URL —
`{LIQUID_MONITOR_CRON_ROUTE_PREFIX}/{code}` (default `liquid-monitor/cron/{code}`):

```php
// routes/console.php
Schedule::call(fn () => Http::post(url('/liquid-monitor/cron/daily-report')))->daily();
```

The route serves two roles depending on the request body, mirroring the Nette connector's
`Cron::scheduleOrStartJob()`:

- **no `jobId`** → registers the run with the monitor (`POST /api/connector/schedule-job`,
  `cronUrl` = this same route).
- **`jobId` present** → the monitor is calling back to actually execute the job. Runs the
  registered handler, wrapped in `start-job` → `finish-job`/`fail-job` reporting. A true PHP
  fatal (OOM, etc.) that skips normal exception handling is still caught by a
  `register_shutdown_function` safety net and reported as failed.

This keeps `repeatCount` retries, `canRunConcurrently`/`canRunConcurrentlyCron`, the
monitor's queue-size limit and the "Run now" admin button working exactly as they do for
Nette projects — no monitor-side changes needed.

**Securing the webhook route:** unlike Nette, this route has no framework-level auth by
default (same as the Nette connector's presenter action). Anyone who knows the URL can
trigger `schedule-job`, and the monitor is the only party expected to know the `jobId` for
a "start" call. Put it behind your own IP allowlist/reverse-proxy rule if the app is
publicly reachable.

## Error reporting

A `liquid_monitor` log channel is auto-registered (no `config/logging.php` edit needed).
Log through it directly:

```php
Log::channel('liquid_monitor')->error('Something went wrong');
```

Or wire it into the exception handler for automatic reporting of uncaught exceptions
(`bootstrap/app.php`):

```php
use LiquidMonitorConnector\ErrorReporting\ErrorReporter;

->withExceptions(function (Exceptions $exceptions) {
    $exceptions->reportable(fn (Throwable $e) => app(ErrorReporter::class)->log($e, 'error'));
})
```

Use `LiquidMonitorConnector\Exceptions\WeakException` for low-importance errors that
should still be logged on the monitor but not trigger a Slack alert.

## Read-only DB query proxy

Disabled by default. Enable and set a token:

```dotenv
LIQUID_MONITOR_DB_QUERY_ENABLED=true
LIQUID_MONITOR_DB_QUERY_TOKEN=some-long-random-token
```

Exposes `POST {route_prefix}/query` (default `liquid-monitor/db-query/api/query`),
matching the Nette connector's `DbQueryApiPresenter` contract exactly: the caller (the
monitor) supplies both the SQL and the DB connection credentials in the request body. A
1:1 port of the same guard enforces SELECT/WITH-only, blocks multiple statements and
DML/DDL keywords, wraps the query in a capped `LIMIT` subquery, and applies a
driver-level statement timeout. MySQL/MariaDB and PostgreSQL are supported.

## Log viewer proxy

Disabled by default. Enable and set a token:

```dotenv
LIQUID_MONITOR_LOG_VIEWER_ENABLED=true
LIQUID_MONITOR_LOG_VIEWER_TOKEN=some-long-random-token
```

Exposes `list` / `stat` / `view` / `search` / `download` under `{route_prefix}` (default
`liquid-monitor/log-viewer/api`) over `storage/logs` by default
(`LIQUID_MONITOR_LOG_VIEWER_DIR` to change it). This is a **1:1 port** of
`liquiddesign/nette-log-viewer`'s `LogViewerApiPresenter`/`LogReader` — identical
endpoints, query params, response shapes and error codes/messages — so that package's
`log-viewer-api` skill (used by an AI agent to browse logs over HTTP) works unmodified
against a Laravel-hosted connector too.

### Security model difference from the Nette connector

The Nette connector gates both proxies via Tracy's *per-request* debug mode: an IP
allowlist that makes `Debugger::$productionMode` resolve to `false` only for the
monitor's own IP, while everyone else sees a normal production app. Laravel's
`APP_DEBUG` is a single, static, global flag — it can't reproduce that per-request
trick without exposing debug mode to every visitor.

Instead, `AuthorizeMonitorRequest` middleware enforces, fail-closed:

1. An optional IP allowlist (`LIQUID_MONITOR_{DB_QUERY,LOG_VIEWER}_ALLOWED_IPS`,
   comma-separated).
2. A **mandatory** `X-Api-Key` header, compared with `hash_equals()`.

Both proxies are disabled by default; enabling one without a token is not possible — the
middleware always requires one. This is a deliberate, documented hardening over the
Nette side's optional token, not an accidental behavior difference.

## Version handshake

Every outbound request carries `X-Connector-Version` (see `LiquidMonitorConnector\Support\Version::CURRENT`,
currently `2.0.1` to match the backend's `supported_versions` at the time this package
was created). If the backend reports the version as unsupported (`426 Upgrade Required`,
or the legacy `X-Connector-Version-Status: unsupported` header), a warning is logged via
the default Laravel logger so an outdated connector install is visible in the app's own
logs. Bump `Version::CURRENT` whenever the connector→backend contract changes; see the
backend's `connector-versioning` skill before introducing a new major.

## Deploy hooks

`LiquidMonitorConnector\Deploy\DeployClient` wraps
`/api/connector/deploy/{is-deploy,start-deploy,deploy-done}` for wiring into a deploy
script.

## Testing

```bash
composer install
vendor/bin/pest
```

Uses Orchestra Testbench. `ReadOnlyQueryRunner`'s pre-connect guard is covered directly;
a full round trip against a live MySQL/Postgres instance is exercised manually / in a
host app's own test suite, not here.
