<?php

declare(strict_types=1);

namespace LiquidMonitorConnector;

use Illuminate\Support\ServiceProvider;
use LiquidMonitorConnector\Deploy\DeployClient;
use LiquidMonitorConnector\ErrorReporting\ErrorReporter;
use LiquidMonitorConnector\Http\MonitorHttpClient;
use LiquidMonitorConnector\Logging\LiquidMonitorLogHandler;

final class LiquidMonitorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/liquid-monitor.php', 'liquid-monitor');

        // Bound `scoped`, not `singleton`: state (currentJobId) must reset between
        // requests, which matters once the app runs under Octane/long-lived workers.
        $this->app->scoped(LiquidMonitorConnector::class, static fn (): LiquidMonitorConnector => new LiquidMonitorConnector(
            new MonitorHttpClient(config('liquid-monitor.verify_tls', true)),
        ));

        $this->app->scoped(ErrorReporter::class, static fn (): ErrorReporter => new ErrorReporter(
            new MonitorHttpClient(config('liquid-monitor.verify_tls', true)),
        ));

        $this->app->bind(DeployClient::class, static fn (): DeployClient => new DeployClient(
            new MonitorHttpClient(config('liquid-monitor.verify_tls', true)),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/liquid-monitor.php' => config_path('liquid-monitor.php'),
            ], 'liquid-monitor-config');
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/liquid-monitor.php');

        $this->registerLogChannel();
    }

    /**
     * Auto-registers the `liquid_monitor` log channel so `Log::channel('liquid_monitor')`
     * works without touching `config/logging.php`. Any channel the app already defines
     * under that name takes precedence (merged on top of the package default).
     */
    private function registerLogChannel(): void
    {
        $default = [
            'driver' => 'monolog',
            'handler' => LiquidMonitorLogHandler::class,
            'level' => 'error',
        ];

        $this->app['config']->set(
            'logging.channels.liquid_monitor',
            \array_merge($default, (array) config('logging.channels.liquid_monitor', [])),
        );
    }
}
