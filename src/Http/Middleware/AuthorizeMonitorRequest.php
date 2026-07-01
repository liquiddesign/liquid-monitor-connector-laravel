<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gates the log-viewer and DB-query proxy endpoints.
 *
 * The Nette connector gates these via Tracy's per-request debug mode (an IP
 * allowlist that makes `Debugger::$productionMode` resolve to `false` only for
 * the monitor's IP). Laravel's `APP_DEBUG` is a static, global flag — it can't
 * reproduce that per-request trick — so this middleware replaces it with an
 * explicit, fail-closed check: an optional IP allowlist plus a mandatory
 * `X-Api-Key` token (constant-time compare). Both endpoints are disabled by
 * default (`liquid-monitor.{log_viewer,db_query}.enabled = false`); routes for
 * a disabled endpoint are not even registered, see the service provider.
 *
 * Error response shape (`{"error": "...", "code": <status>}`) matches the Nette
 * connector's presenters, so tooling written against that contract (e.g. the
 * `log-viewer-api` skill) works unmodified against a Laravel-hosted connector.
 */
final class AuthorizeMonitorRequest
{
    public function handle(Request $request, Closure $next, string $configKey): mixed
    {
        $config = (array) config("liquid-monitor.{$configKey}", []);

        $allowedIps = $config['allowed_ips'] ?? [];

        if ($allowedIps !== [] && ! \in_array($request->ip(), $allowedIps, true)) {
            return $this->error(403, 'Access denied');
        }

        $token = $config['api_token'] ?? null;

        if (! \is_string($token) || $token === '') {
            return $this->error(403, 'Access denied');
        }

        $provided = (string) $request->header('X-Api-Key', '');

        if (! \hash_equals($token, $provided)) {
            return $this->error(403, 'Invalid API key');
        }

        return $next($request);
    }

    private function error(int $code, string $message): JsonResponse
    {
        return response()->json(['error' => $message, 'code' => $code], $code);
    }
}
