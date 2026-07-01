<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Logging;

use LiquidMonitorConnector\ErrorReporting\ErrorReporter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Monolog handler that forwards log records to the Liquid Monitor backend via
 * {@see ErrorReporter}. Wire it up as a channel in `config/logging.php`:
 *
 *   'liquid_monitor' => [
 *       'driver' => 'monolog',
 *       'handler' => \LiquidMonitorConnector\Logging\LiquidMonitorLogHandler::class,
 *       'level' => 'error',
 *   ],
 *
 * and either log through it explicitly (`Log::channel('liquid_monitor')->error(...)`),
 * add it to your default stack, or wire it into the exception handler:
 *
 *   ->withExceptions(fn (Exceptions $e) => $e->reportable(
 *       fn (\Throwable $e) => app(\LiquidMonitorConnector\ErrorReporting\ErrorReporter::class)
 *           ->log($e, 'error'),
 *   ));
 */
final class LiquidMonitorLogHandler extends AbstractProcessingHandler
{
    public function __construct(int|string|Level $level = Level::Error, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $levels = config('liquid-monitor.log.levels', ['emergency', 'alert', 'critical', 'error']);
        $levelName = \strtolower($record->level->getName());

        if (! \in_array($levelName, $levels, true)) {
            return;
        }

        $context = $record->context;
        $exception = $context['exception'] ?? null;
        $weak = (bool) ($context['weak'] ?? false);

        $message = $exception instanceof \Throwable ? $exception : $record->message;

        app(ErrorReporter::class)->log($message, $levelName, $weak);
    }
}
