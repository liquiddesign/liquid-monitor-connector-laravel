<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Deploy;

use LiquidMonitorConnector\Http\MonitorHttpClient;

/**
 * Thin wrapper around the monitor's deploy lifecycle endpoints
 * (`/api/connector/deploy/{is-deploy,start-deploy,deploy-done}`). Wire it into a
 * deploy script (e.g. an Artisan command run from `composer deploy`).
 */
final class DeployClient
{
    public function __construct(private readonly MonitorHttpClient $http) {}

    /**
     * @return array<string, mixed>|null Pending deploy payload, or null if none is pending.
     */
    public function isDeploy(): ?array
    {
        $response = $this->http->post($this->endpoint('is-deploy'), $this->apiKey(), $this->enabled(), []);

        return $response?->successful() ? $response->json() : null;
    }

    public function startDeploy(int $deployId): void
    {
        $this->http->post($this->endpoint('start-deploy'), $this->apiKey(), $this->enabled(), ['deployId' => $deployId]);
    }

    public function deployDone(int $deployId, int $resultCode, ?string $result = null): void
    {
        $this->http->post($this->endpoint('deploy-done'), $this->apiKey(), $this->enabled(), [
            'deployId' => $deployId,
            'resultCode' => $resultCode,
            'result' => $result,
        ]);
    }

    private function endpoint(string $action): string
    {
        return \rtrim($this->baseUrl(), '/').'/connector/deploy/'.$action;
    }

    private function baseUrl(): string
    {
        return (string) (config('liquid-monitor.cron.url') ?? config('liquid-monitor.url'));
    }

    private function apiKey(): ?string
    {
        return config('liquid-monitor.cron.api_key') ?? config('liquid-monitor.api_key');
    }

    private function enabled(): bool
    {
        return (bool) config('liquid-monitor.enabled', true);
    }
}
