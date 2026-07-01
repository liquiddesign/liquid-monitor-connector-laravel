<?php

declare(strict_types=1);

namespace LiquidMonitorConnector;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LiquidMonitorConnector\Http\Controllers\CronWebhookController;
use LiquidMonitorConnector\Http\MonitorHttpClient;
use LiquidMonitorConnector\Jobs\JobContext;
use LiquidMonitorConnector\Logging\ExceptionSerializer;

/**
 * Cron/job lifecycle service — the Laravel equivalent of `LiquidMonitorConnector\Cron`
 * from the Nette connector. A single registered route (see
 * {@see CronWebhookController}) serves double
 * duty depending on the request body:
 *
 *  - no `jobId`  -> "schedule" mode: register/enqueue the run with the monitor
 *                   (`POST /api/connector/schedule-job`), cronUrl = this same route.
 *  - `jobId`     -> "start" mode: the monitor is calling back to actually execute the
 *                   job. Runs the registered handler, wrapped in start/finish/fail
 *                   reporting.
 *
 * Bound as a *scoped* (not singleton) service — state (`currentJobId`) must reset
 * between requests, which matters under Octane/long-running workers.
 */
final class LiquidMonitorConnector
{
    /**
     * @var array<string, array{handler: callable, options: array<string, mixed>}>
     */
    private array $crons = [];

    private ?string $currentJobId = null;

    private ?string $currentCronCode = null;

    public function __construct(private readonly MonitorHttpClient $http) {}

    /**
     * Register a cron job handler. Call from a service provider's `boot()`.
     *
     * @param  array{
     *     name?: string|null,
     *     description?: string|null,
     *     repeatCount?: int,
     *     canRunConcurrently?: bool,
     *     canRunConcurrentlyCron?: bool,
     *     timeout?: int|null,
     *     maxQueueSize?: int|null,
     *     createIfNotExists?: bool,
     *     arguments?: array<mixed>|null,
     * }  $options
     */
    public function registerCron(string $code, callable $handler, array $options = []): void
    {
        $this->crons[$code] = ['handler' => $handler, 'options' => $options];
    }

    public function isRegistered(string $code): bool
    {
        return isset($this->crons[$code]);
    }

    public function currentJobId(): ?string
    {
        return $this->currentJobId;
    }

    public function currentCronCode(): ?string
    {
        return $this->currentCronCode;
    }

    /**
     * Entry point for the inbound webhook route. Dual-mode dispatch, see class docblock.
     */
    public function handleCronRequest(string $code, Request $request): JsonResponse
    {
        if (! isset($this->crons[$code])) {
            abort(404, "Cron '{$code}' is not registered.");
        }

        $this->currentCronCode = $code;
        $jobId = $request->input('jobId');

        if ((bool) $request->input('skipMonitor', false)) {
            return response()->json(['message' => 'skipped']);
        }

        if ($jobId === null) {
            $this->scheduleJob($code, $request);

            return response()->json(['message' => 'scheduled']);
        }

        $this->currentJobId = (string) $jobId;
        $handler = $this->crons[$code]['handler'];

        $this->startJob();
        $this->registerShutdownGuard();

        try {
            $handler(new JobContext($this, $request));
            $this->finishJob();

            return response()->json(['message' => 'finished']);
        } catch (\Throwable $e) {
            $this->failJob($e);

            return response()->json(['message' => 'failed', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Register with the monitor that this cron is about to run (or should be queued).
     * `cronUrl` is the current request URL, i.e. this same route — the monitor calls
     * it back with `jobId` set once the job is dispatched from its queue.
     */
    public function scheduleJob(string $code, Request $request): void
    {
        $options = $this->crons[$code]['options'] ?? [];

        $params = [
            'cronId' => $code,
            'timeout' => (int) (\ini_get('max_execution_time') ?: 0),
            'cronName' => $options['name'] ?? null,
            'cronUrl' => $request->fullUrl(),
            'cronRepeatCount' => $options['repeatCount'] ?? 0,
            'cronCanRunConcurrently' => $options['canRunConcurrently'] ?? false,
            'cronCanRunConcurrentlyCron' => $options['canRunConcurrentlyCron'] ?? false,
            'cronDescription' => $options['description'] ?? null,
            'cronTimeout' => $options['timeout'] ?? null,
            'cronMaxQueueSize' => $options['maxQueueSize'] ?? null,
            'createIfNotExists' => $options['createIfNotExists'] ?? true,
            'arguments' => $options['arguments'] ?? null,
        ];

        $this->http->post($this->connectorEndpoint('schedule-job'), $this->cronApiKey(), $this->enabled(), $params);
    }

    public function startJob(mixed $data = null): void
    {
        $this->send('start-job', ['data' => $this->processData($data)]);
    }

    public function progressJob(mixed $data = null): void
    {
        if ($this->currentJobId === null) {
            return;
        }

        $this->send('progress-job', ['data' => $this->processData($data)]);
    }

    public function finishJob(mixed $data = null): void
    {
        if ($this->currentJobId === null) {
            return;
        }

        $this->send('finish-job', ['data' => $this->processData($data), 'ram' => $this->peakMemoryMb()]);
        $this->currentJobId = null;
    }

    public function failJob(mixed $data = null): void
    {
        if ($this->currentJobId === null) {
            return;
        }

        $this->send('fail-job', ['data' => $this->processData($data), 'ram' => $this->peakMemoryMb()]);
        $this->currentJobId = null;
    }

    public function isCronRunning(string $code): bool
    {
        $response = $this->http->get($this->frontEndpoint("cron/{$code}/is-running"), $this->cronApiKey());

        return $response !== null && $response->successful();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLastCronJobLog(string $code): ?array
    {
        $response = $this->http->get($this->frontEndpoint("cron/{$code}/last-job-log"), $this->cronApiKey());

        return $response?->successful() ? $response->json() : null;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function getCronOverview(): ?array
    {
        $response = $this->http->get($this->frontEndpoint('cron/overview'), $this->cronApiKey());

        return $response?->successful() ? $response->json('data') : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCronJobLogsStats(string $code): ?array
    {
        $response = $this->http->get($this->frontEndpoint("cron/{$code}/joblogs-stats"), $this->cronApiKey());

        return $response?->successful() ? $response->json() : null;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function send(string $endpoint, array $params): void
    {
        $this->http->post($this->connectorEndpoint($endpoint), $this->cronApiKey(), $this->enabled(), ['jobId' => $this->currentJobId] + $params);
    }

    /**
     * PHP-fatal safety net mirroring the Nette connector's `register_shutdown_function`:
     * a job that dies without calling finishJob()/failJob() (OOM, uncaught fatal) is
     * still reported as failed instead of hanging forever "running" on the monitor.
     * Ordinary exceptions are already caught in {@see handleCronRequest()} — this only
     * fires for a true PHP-level fatal.
     */
    private function registerShutdownGuard(): void
    {
        $guardedJobId = $this->currentJobId;

        \register_shutdown_function(function () use ($guardedJobId): void {
            if ($this->currentJobId !== $guardedJobId) {
                return;
            }

            $error = \error_get_last();
            $data = ['reason' => 'PHP shutdown triggered before finishJob()/failJob() was called.'];

            if ($error !== null && \in_array($error['type'], [\E_ERROR, \E_PARSE, \E_CORE_ERROR, \E_COMPILE_ERROR], true)) {
                $data['error'] = $error;
            }

            $this->failJob($data);
        });
    }

    /**
     * @return array<mixed>|null
     */
    private function processData(mixed $data): ?array
    {
        if ($data === null) {
            return null;
        }

        if ($data instanceof \Throwable) {
            return ['exception' => $data->getMessage(), 'trace' => ExceptionSerializer::traces($data)];
        }

        if (\is_string($data)) {
            return [$data];
        }

        if (\is_array($data)) {
            return $data;
        }

        return ['value' => $data];
    }

    private function peakMemoryMb(): int
    {
        return (int) (\memory_get_peak_usage(true) / 1024 / 1024);
    }

    private function enabled(): bool
    {
        return (bool) config('liquid-monitor.enabled', true);
    }

    private function cronApiKey(): ?string
    {
        return config('liquid-monitor.cron.api_key') ?? config('liquid-monitor.api_key');
    }

    /**
     * Base monitor API URL, e.g. `https://monitor.example/api` — WITHOUT a `/connector`
     * or `/front` suffix, those are appended by {@see connectorEndpoint()}/{@see frontEndpoint()}.
     */
    private function baseUrl(): string
    {
        return (string) (config('liquid-monitor.cron.url') ?? config('liquid-monitor.url'));
    }

    private function connectorEndpoint(string $path): string
    {
        return \rtrim($this->baseUrl(), '/').'/connector/'.\ltrim($path, '/');
    }

    private function frontEndpoint(string $path): string
    {
        return \rtrim($this->baseUrl(), '/').'/front/'.\ltrim($path, '/');
    }
}
