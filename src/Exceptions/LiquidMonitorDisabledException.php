<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Exceptions;

use LiquidMonitorConnector\Http\MonitorHttpClient;

/**
 * Thrown by {@see MonitorHttpClient::post()} when a channel
 * (cron/log) is disabled or missing its API key and the caller asked to be notified
 * (`$throw = true`) instead of having the request silently skipped.
 */
class LiquidMonitorDisabledException extends \RuntimeException {}
