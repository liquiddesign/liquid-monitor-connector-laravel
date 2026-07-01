<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use LiquidMonitorConnector\Http\Controllers\CronWebhookController;
use LiquidMonitorConnector\Http\Controllers\DbQueryApiController;
use LiquidMonitorConnector\Http\Controllers\LogViewerApiController;
use LiquidMonitorConnector\Http\Middleware\AuthorizeMonitorRequest;

/*
|--------------------------------------------------------------------------
| Liquid Monitor connector routes
|--------------------------------------------------------------------------
|
| Registered directly (no `web`/`api` middleware group) so the monitor's
| callback POSTs aren't rejected by CSRF/session middleware — mirrors the
| Nette connector's presenter actions, which have no such protection either.
|
*/

$cronPrefix = \trim((string) config('liquid-monitor.cron.route_prefix', 'liquid-monitor/cron'), '/');

Route::match(['GET', 'POST'], "{$cronPrefix}/{code}", CronWebhookController::class)
    ->name('liquid-monitor.cron');

if ((bool) config('liquid-monitor.log_viewer.enabled', false)) {
    $logViewerPrefix = \trim((string) config('liquid-monitor.log_viewer.route_prefix', 'liquid-monitor/log-viewer/api'), '/');

    Route::middleware(AuthorizeMonitorRequest::class.':log_viewer')
        ->prefix($logViewerPrefix)
        ->group(function (): void {
            Route::get('list', [LogViewerApiController::class, 'list'])->name('liquid-monitor.log-viewer.list');
            Route::get('stat', [LogViewerApiController::class, 'stat'])->name('liquid-monitor.log-viewer.stat');
            Route::get('view', [LogViewerApiController::class, 'view'])->name('liquid-monitor.log-viewer.view');
            Route::get('search', [LogViewerApiController::class, 'search'])->name('liquid-monitor.log-viewer.search');
            Route::get('download', [LogViewerApiController::class, 'download'])->name('liquid-monitor.log-viewer.download');
        });
}

if ((bool) config('liquid-monitor.db_query.enabled', false)) {
    $dbQueryPrefix = \trim((string) config('liquid-monitor.db_query.route_prefix', 'liquid-monitor/db-query/api'), '/');

    Route::post("{$dbQueryPrefix}/query", [DbQueryApiController::class, 'query'])
        ->middleware(AuthorizeMonitorRequest::class.':db_query')
        ->name('liquid-monitor.db-query.query');
}
