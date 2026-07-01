<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LiquidMonitorConnector\ErrorReporting\ErrorReporter;
use LiquidMonitorConnector\Exceptions\WeakException;

it('posts a plain message to the monitor log endpoint', function () {
    Http::fake(['monitor.example/api/connector/log' => Http::response(['ok' => true], 201)]);

    app(ErrorReporter::class)->log('Something went wrong', 'error');

    Http::assertSent(fn ($request) => $request->url() === 'https://monitor.example/api/connector/log'
        && $request['level'] === 'error'
        && $request['message'] === 'Something went wrong'
        && $request['apiKey'] === 'test-api-key');
});

it('serializes an exception into message/data/code', function () {
    Http::fake(['monitor.example/api/connector/log' => Http::response([], 201)]);

    app(ErrorReporter::class)->log(new RuntimeException('boom', 42), 'critical');

    Http::assertSent(function ($request) {
        $data = \json_decode((string) $request['data'], true);

        return $request['message'] === 'boom'
            && $request['code'] === '42'
            && isset($data['trace'], $data['file'], $data['line']);
    });
});

it('flags WeakException payloads as weak', function () {
    Http::fake(['monitor.example/api/connector/log' => Http::response([], 201)]);

    app(ErrorReporter::class)->log(new WeakException('minor issue'), 'warning');

    Http::assertSent(fn ($request) => $request['weak'] === true);
});

it('truncates overly long messages to 4850 characters', function () {
    Http::fake(['monitor.example/api/connector/log' => Http::response([], 201)]);

    app(ErrorReporter::class)->log(\str_repeat('x', 5000), 'error');

    Http::assertSent(fn ($request) => \mb_strlen((string) $request['message']) === 4850);
});

it('does not send anything when the channel is disabled', function () {
    Http::fake();

    config()->set('liquid-monitor.enabled', false);

    app(ErrorReporter::class)->log('nope', 'error');

    Http::assertNothingSent();
});

it('is reachable through the liquid_monitor log channel', function () {
    Http::fake(['monitor.example/api/connector/log' => Http::response([], 201)]);

    Log::channel('liquid_monitor')->error('channel test');

    Http::assertSent(fn ($request) => $request['message'] === 'channel test' && $request['level'] === 'error');
});

it('filters out levels not in the configured allowlist', function () {
    Http::fake(['monitor.example/api/connector/log' => Http::response([], 201)]);

    // 'critical' clears Monolog's own channel-level threshold ('error', set in the
    // service provider's default channel config) so it reaches the handler's write() —
    // it should then be dropped there by the connector's own `log.levels` allowlist,
    // which is intentionally independent of Monolog's severity gate.
    config()->set('liquid-monitor.log.levels', ['error']);

    Log::channel('liquid_monitor')->critical('should be dropped by the handler-level filter');

    Http::assertNothingSent();
});

it('logs a warning when the backend reports an unsupported connector version', function () {
    Http::fake(['monitor.example/api/connector/log' => Http::response([], 426, ['X-Connector-Supported-Versions' => '3'])]);

    Log::spy();

    app(ErrorReporter::class)->log('trigger', 'error');

    Log::shouldHaveReceived('warning')->once();
});
