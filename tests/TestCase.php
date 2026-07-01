<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Tests;

use LiquidMonitorConnector\LiquidMonitorServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LiquidMonitorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('liquid-monitor.url', 'https://monitor.example/api');
        $app['config']->set('liquid-monitor.api_key', 'test-api-key');
        $app['config']->set('liquid-monitor.enabled', true);

        // Log-viewer/DB-query proxies are disabled by default (routes registered
        // conditionally in the service provider's boot(), which already ran by the
        // time a beforeEach() would fire) so they're switched on here, once, for the
        // whole suite — harmless for tests that never hit those routes.
        $app['config']->set('liquid-monitor.db_query.enabled', true);
        $app['config']->set('liquid-monitor.db_query.api_token', 'secret-token');

        $app['config']->set('liquid-monitor.log_viewer.enabled', true);
        $app['config']->set('liquid-monitor.log_viewer.api_token', 'secret-token');
        $app['config']->set('liquid-monitor.log_viewer.log_dir', sys_get_temp_dir().'/liquid-monitor-connector-tests-'.getmypid());
    }
}
