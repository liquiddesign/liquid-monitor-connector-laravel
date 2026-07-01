<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LiquidMonitorConnector\LiquidMonitorConnector;

/**
 * Single inbound endpoint used both by the app's own scheduler (to register a run
 * with the monitor) and by the monitor itself (to trigger the actual execution).
 * See {@see LiquidMonitorConnector::handleCronRequest()} for the dual-mode logic.
 */
final class CronWebhookController
{
    public function __invoke(Request $request, string $code, LiquidMonitorConnector $connector): JsonResponse
    {
        return $connector->handleCronRequest($code, $request);
    }
}
