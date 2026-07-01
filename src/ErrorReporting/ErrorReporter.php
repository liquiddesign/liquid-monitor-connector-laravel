<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\ErrorReporting;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LiquidMonitorConnector\Exceptions\WeakException;
use LiquidMonitorConnector\Http\MonitorHttpClient;
use LiquidMonitorConnector\Logging\ExceptionSerializer;

/**
 * Error/log reporting channel — independent of the cron lifecycle, so a project can wire
 * up just error collection (via the `liquid_monitor` log channel) without registering any
 * crons. Posts to `/api/connector/log`. Mirrors `LiquidMonitorConnector\ErrorReporter` +
 * `LiquidMonitorConnector\LiquidMonitorLogger` from the Nette connector.
 */
final class ErrorReporter
{
    private const LOG_ENDPOINT = '/connector/log';

    private const MAX_MESSAGE_LENGTH = 4850;

    public function __construct(private readonly MonitorHttpClient $http) {}

    /**
     * @param  \Throwable|array<mixed>|string  $message
     */
    public function log(\Throwable|array|string $message, string $level, bool $weak = false): void
    {
        if ($message instanceof WeakException) {
            $weak = true;
        }

        [$messageString, $data, $code] = $this->parseMessage($message);

        $request = $this->currentRequest();

        $params = [
            'url' => $request?->fullUrl(),
            'message' => $messageString,
            'data' => $data,
            'remoteAddress' => $request?->ip(),
            'method' => $request?->method(),
            'request_body' => $request?->getContent() ?: null,
            'duration' => $this->requestDurationMs(),
            'memory_usage' => (int) (\memory_get_peak_usage(true) / 1_000_000),
            'code' => $code !== null ? (string) $code : null,
            'weak' => $weak,
            'job_id' => $this->currentJobId($request),
            'identity' => $this->identityJson(),
        ];

        $url = \rtrim($this->logUrl(), '/').self::LOG_ENDPOINT;

        $this->http->post($url, $this->logApiKey(), $this->enabled(), $params + ['level' => $level]);
    }

    private function currentRequest(): ?Request
    {
        return app()->bound('request') ? app('request') : null;
    }

    private function currentJobId(?Request $request): ?string
    {
        $jobId = $request?->input('jobId');

        return $jobId !== null ? (string) $jobId : null;
    }

    private function requestDurationMs(): int
    {
        if (! \defined('LARAVEL_START')) {
            return 0;
        }

        return (int) ((\microtime(true) - \LARAVEL_START) * 1000);
    }

    private function identityJson(): ?string
    {
        if (! Auth::hasUser()) {
            return null;
        }

        $user = Auth::user();

        try {
            return \json_encode($user instanceof Arrayable ? $user->toArray() : (array) $user, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param  \Throwable|array<mixed>|string  $message
     * @return array{0: string, 1: ?string, 2: mixed}
     */
    private function parseMessage(\Throwable|array|string $message): array
    {
        $code = null;

        if ($message instanceof \Throwable) {
            $data = ExceptionSerializer::toArray($message);
            $code = $message->getCode();
            $messageString = $message->getMessage();
        } elseif (\is_array($message)) {
            $data = ['message' => $message, 'trace' => \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS)];
            $messageString = (string) (\reset($message) ?: '');
        } else {
            $data = ['trace' => \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS)];
            $messageString = $message;
        }

        if (\mb_strlen($messageString) > self::MAX_MESSAGE_LENGTH) {
            $messageString = \mb_substr($messageString, 0, self::MAX_MESSAGE_LENGTH);
        }

        try {
            $encodedData = \json_encode($data, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $encodedData = null;
        }

        return [$messageString, $encodedData, $code];
    }

    private function logUrl(): string
    {
        return (string) (config('liquid-monitor.log.url') ?? config('liquid-monitor.url'));
    }

    private function logApiKey(): ?string
    {
        return config('liquid-monitor.log.api_key') ?? config('liquid-monitor.api_key');
    }

    private function enabled(): bool
    {
        return (bool) config('liquid-monitor.enabled', true);
    }
}
