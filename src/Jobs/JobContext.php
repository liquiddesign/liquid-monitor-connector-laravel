<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Jobs;

use Illuminate\Http\Request;
use LiquidMonitorConnector\LiquidMonitorConnector;

/**
 * Passed to a registered cron handler so it can report progress heartbeats and read
 * the custom arguments the monitor forwarded when it triggered this run.
 */
final class JobContext
{
    public function __construct(
        private readonly LiquidMonitorConnector $connector,
        private readonly Request $request,
    ) {}

    public function progress(mixed $data = null): void
    {
        $this->connector->progressJob($data);
    }

    /**
     * @return array<mixed>|null
     */
    public function arguments(): ?array
    {
        $arguments = $this->request->input('arguments');

        return \is_array($arguments) ? $arguments : null;
    }

    public function request(): Request
    {
        return $this->request;
    }
}
