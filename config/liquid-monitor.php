<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Monitor base URL
    |--------------------------------------------------------------------------
    |
    | Base API URL of the Liquid Monitor instance, e.g. "https://monitor.example/api".
    | Do NOT include "/connector" or "/front" — those are appended per endpoint.
    | Shared fallback for the cron and log channels below; either channel can
    | override it to point at a different monitor instance.
    |
    */
    'url' => env('LIQUID_MONITOR_URL'),

    'api_key' => env('LIQUID_MONITOR_API_KEY'),

    'enabled' => env('LIQUID_MONITOR_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | TLS verification
    |--------------------------------------------------------------------------
    |
    | true (default) = verify against system CAs, false = disabled (dev only with
    | a self-signed cert — this allows MITM interception of the API key!), or a
    | string path to a custom CA bundle (preferred for self-signed dev certs).
    |
    */
    'verify_tls' => env('LIQUID_MONITOR_VERIFY_TLS', true),

    /*
    |--------------------------------------------------------------------------
    | Cron / job lifecycle channel
    |--------------------------------------------------------------------------
    */
    'cron' => [
        'url' => env('LIQUID_MONITOR_CRON_URL'),
        'api_key' => env('LIQUID_MONITOR_CRON_API_KEY'),

        // Path prefix for the inbound webhook route, e.g. "liquid-monitor/cron/{code}".
        'route_prefix' => env('LIQUID_MONITOR_CRON_ROUTE_PREFIX', 'liquid-monitor/cron'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Error / log reporting channel
    |--------------------------------------------------------------------------
    */
    'log' => [
        'url' => env('LIQUID_MONITOR_LOG_URL'),
        'api_key' => env('LIQUID_MONITOR_LOG_API_KEY'),

        // Monolog level names that get forwarded to the monitor.
        'levels' => ['emergency', 'alert', 'critical', 'error'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Log viewer proxy
    |--------------------------------------------------------------------------
    |
    | Disabled by default. When enabled, gated by AuthorizeMonitorRequest (IP
    | allowlist + mandatory token) — see that class's docblock for why this
    | replaces the Nette connector's Tracy-debug-mode gate.
    |
    */
    'log_viewer' => [
        'enabled' => env('LIQUID_MONITOR_LOG_VIEWER_ENABLED', false),
        'route_prefix' => env('LIQUID_MONITOR_LOG_VIEWER_ROUTE_PREFIX', 'liquid-monitor/log-viewer/api'),
        'log_dir' => env('LIQUID_MONITOR_LOG_VIEWER_DIR', storage_path('logs')),
        'api_token' => env('LIQUID_MONITOR_LOG_VIEWER_TOKEN'),
        'allowed_ips' => \array_filter(\explode(',', (string) env('LIQUID_MONITOR_LOG_VIEWER_ALLOWED_IPS', ''))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Read-only DB query proxy
    |--------------------------------------------------------------------------
    |
    | Disabled by default. Connection credentials are supplied by the monitor
    | in each request body — this endpoint only needs its own IP/token gate.
    |
    */
    'db_query' => [
        'enabled' => env('LIQUID_MONITOR_DB_QUERY_ENABLED', false),
        'route_prefix' => env('LIQUID_MONITOR_DB_QUERY_ROUTE_PREFIX', 'liquid-monitor/db-query/api'),
        'api_token' => env('LIQUID_MONITOR_DB_QUERY_TOKEN'),
        'allowed_ips' => \array_filter(\explode(',', (string) env('LIQUID_MONITOR_DB_QUERY_ALLOWED_IPS', ''))),
    ],
];
