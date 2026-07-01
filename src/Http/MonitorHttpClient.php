<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LiquidMonitorConnector\Exceptions\LiquidMonitorDisabledException;
use LiquidMonitorConnector\Support\Version;

/**
 * Shared HTTP transport to the Liquid Monitor backend. Keeps the version handshake
 * (unsupported-connector detection) in one place so the cron and error-reporting
 * channels don't duplicate it. Mirrors `LiquidMonitorConnector\MonitorHttpClient`
 * from the Nette connector.
 */
final class MonitorHttpClient
{
    /**
     * @param  bool|string  $verifyTls  TLS peer verification: `true` (default) = verify against
     *                                  system CAs, `false` = disabled (dev only with a self-signed cert — this allows MITM
     *                                  interception of the API key!), string = path to a custom CA bundle.
     */
    public function __construct(private readonly bool|string $verifyTls = true) {}

    /**
     * POST to the monitor. A disabled channel (missing apiKey / enabled:false) is silently
     * skipped — unless `$throw`, in which case a {@see LiquidMonitorDisabledException} is
     * thrown instead (cron scheduling needs to distinguish the two).
     *
     * @param  array<string, mixed>  $params  Request body without `apiKey` (merged in from `$apiKey`).
     */
    public function post(string $url, ?string $apiKey, bool $enabled, array $params, bool $throw = false): ?Response
    {
        if (! $apiKey || ! $enabled) {
            if ($throw) {
                throw new LiquidMonitorDisabledException;
            }

            return null;
        }

        try {
            $response = $this->client()->post($url, ['apiKey' => $apiKey, ...$params]);
        } catch (ConnectionException $e) {
            Log::warning('Liquid Monitor connector: request failed.', ['url' => $url, 'exception' => $e->getMessage()]);

            if ($throw) {
                throw $e;
            }

            return null;
        }

        $this->warnIfUnsupported($response);

        if ($throw) {
            $response->throw();
        }

        return $response;
    }

    /**
     * GET from the monitor with `apiKey` as a query parameter.
     *
     * @param  array<string, mixed>  $query
     */
    public function get(string $url, ?string $apiKey, array $query = []): ?Response
    {
        if (! $apiKey) {
            return null;
        }

        try {
            $response = $this->client()->get($url, ['apiKey' => $apiKey, ...$query]);
        } catch (ConnectionException $e) {
            Log::warning('Liquid Monitor connector: request failed.', ['url' => $url, 'exception' => $e->getMessage()]);

            return null;
        }

        $this->warnIfUnsupported($response);

        return $response;
    }

    private function client(): PendingRequest
    {
        $request = Http::withHeaders([
            'Accept' => 'application/json',
            Version::HEADER_NAME => Version::CURRENT,
        ])->timeout(15);

        if ($this->verifyTls === false) {
            $request = $request->withOptions(['verify' => false]);
        } elseif (\is_string($this->verifyTls)) {
            $request = $request->withOptions(['verify' => $this->verifyTls]);
        }

        return $request;
    }

    private function warnIfUnsupported(Response $response): void
    {
        $isUnsupported = $response->header(Version::STATUS_HEADER_NAME) === Version::STATUS_UNSUPPORTED
            || $response->status() === 426;

        if (! $isUnsupported) {
            return;
        }

        $supported = $response->header(Version::SUPPORTED_VERSIONS_HEADER_NAME);

        Log::warning(\sprintf(
            'Liquid Monitor backend reports connector version %s as unsupported. Backend supports: %s. Upgrade liquiddesign/liquid-monitor-connector-laravel.',
            Version::CURRENT,
            $supported !== null && $supported !== '' ? $supported : '(unknown)',
        ));
    }
}
