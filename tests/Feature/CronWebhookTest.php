<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use LiquidMonitorConnector\LiquidMonitorConnector;
use LiquidMonitorConnector\Support\Version;

beforeEach(function () {
    Http::fake([
        'monitor.example/api/connector/*' => Http::response(['message' => 'ok'], 200),
    ]);
});

it('schedules the job when called without a jobId', function () {
    app(LiquidMonitorConnector::class)->registerCron('daily-report', function (): void {});

    $this->postJson('/liquid-monitor/cron/daily-report', [])
        ->assertOk()
        ->assertJson(['message' => 'scheduled']);

    Http::assertSent(fn ($request) => $request->url() === 'https://monitor.example/api/connector/schedule-job'
        && $request['cronId'] === 'daily-report'
        && $request['apiKey'] === 'test-api-key');
});

it('runs the registered handler and reports finish when called with a jobId', function () {
    $ran = false;

    app(LiquidMonitorConnector::class)->registerCron('daily-report', function () use (&$ran): void {
        $ran = true;
    });

    $this->postJson('/liquid-monitor/cron/daily-report', ['jobId' => '123'])
        ->assertOk()
        ->assertJson(['message' => 'finished']);

    expect($ran)->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://monitor.example/api/connector/start-job' && $request['jobId'] === '123');
    Http::assertSent(fn ($request) => $request->url() === 'https://monitor.example/api/connector/finish-job' && $request['jobId'] === '123');
});

it('reports fail-job when the handler throws', function () {
    app(LiquidMonitorConnector::class)->registerCron('daily-report', function (): void {
        throw new RuntimeException('boom');
    });

    $this->postJson('/liquid-monitor/cron/daily-report', ['jobId' => '123'])
        ->assertStatus(500)
        ->assertJson(['message' => 'failed']);

    Http::assertSent(fn ($request) => $request->url() === 'https://monitor.example/api/connector/fail-job' && $request['jobId'] === '123');
});

it('forwards progress() calls from the job handler to the monitor', function () {
    app(LiquidMonitorConnector::class)->registerCron('daily-report', function ($ctx): void {
        $ctx->progress(['step' => 1]);
    });

    $this->postJson('/liquid-monitor/cron/daily-report', ['jobId' => '123'])->assertOk();

    Http::assertSent(fn ($request) => $request->url() === 'https://monitor.example/api/connector/progress-job');
});

it('exposes forwarded arguments on the job context', function () {
    $seen = null;

    app(LiquidMonitorConnector::class)->registerCron('daily-report', function ($ctx) use (&$seen): void {
        $seen = $ctx->arguments();
    });

    $this->postJson('/liquid-monitor/cron/daily-report', ['jobId' => '123', 'arguments' => ['foo' => 'bar']])->assertOk();

    expect($seen)->toBe(['foo' => 'bar']);
});

it('returns 404 for an unregistered cron code', function () {
    $this->postJson('/liquid-monitor/cron/unknown', [])->assertStatus(404);
});

it('sends the connector version header on every outbound request', function () {
    app(LiquidMonitorConnector::class)->registerCron('daily-report', function (): void {});

    $this->postJson('/liquid-monitor/cron/daily-report', [])->assertOk();

    Http::assertSent(fn ($request) => $request->hasHeader(Version::HEADER_NAME, Version::CURRENT));
});
