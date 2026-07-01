<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\HealthCheck;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

final class HealthCheckResponse
{
    /**
     * @param  array<int, HealthCheckData>  $data
     */
    public function __construct(private HealthCheckStatusEnum $status, private array $data = []) {}

    public static function ok(): self
    {
        return new self(HealthCheckStatusEnum::OK);
    }

    public static function warning(): self
    {
        return new self(HealthCheckStatusEnum::WARNING);
    }

    public static function error(): self
    {
        return new self(HealthCheckStatusEnum::ERROR);
    }

    public static function critical(): self
    {
        return new self(HealthCheckStatusEnum::CRITICAL);
    }

    public function addData(HealthCheckData $data): self
    {
        $this->data[] = $data;

        if ($this->status->value < $data->getStatus()->value) {
            $this->setStatus($data->getStatus());
        }

        return $this;
    }

    public function setStatus(HealthCheckStatusEnum $status): void
    {
        $this->status = $status;
    }

    public function getResponse(): JsonResponse
    {
        $responseData = [
            'status' => $this->status->name,
            'statusTs' => Carbon::now()->toDateTimeString(),
            'data' => \array_map(static fn (HealthCheckData $data): array => $data->toArray(), $this->data),
        ];

        return response()->json($responseData);
    }
}
