<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Facades;

use Illuminate\Support\Facades\Facade;
use LiquidMonitorConnector\LiquidMonitorConnector;
use LiquidMonitorConnector\LiquidMonitorConnector as Connector;

/**
 * @method static void registerCron(string $code, callable $handler, array<string, mixed> $options = [])
 * @method static bool isRegistered(string $code)
 * @method static string|null currentJobId()
 * @method static string|null currentCronCode()
 * @method static void scheduleJob(string $code, \Illuminate\Http\Request $request)
 * @method static void startJob(mixed $data = null)
 * @method static void progressJob(mixed $data = null)
 * @method static void finishJob(mixed $data = null)
 * @method static void failJob(mixed $data = null)
 * @method static bool isCronRunning(string $code)
 * @method static array<string, mixed>|null getLastCronJobLog(string $code)
 * @method static array<int, array<string, mixed>>|null getCronOverview()
 * @method static array<string, mixed>|null getCronJobLogsStats(string $code)
 *
 * @see LiquidMonitorConnector
 */
final class LiquidMonitor extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Connector::class;
    }
}
