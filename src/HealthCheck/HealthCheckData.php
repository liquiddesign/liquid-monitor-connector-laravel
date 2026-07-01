<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\HealthCheck;

final class HealthCheckData
{
    public function __construct(
        private readonly string $name,
        private readonly mixed $value,
        private readonly HealthCheckStatusEnum $status = HealthCheckStatusEnum::OK,
        private readonly ?string $description = null,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getStatus(): HealthCheckStatusEnum
    {
        return $this->status;
    }

    /**
     * @return array{name: string, value: mixed, status: string, description: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'value' => $this->value,
            'status' => $this->status->name,
            'description' => $this->description,
        ];
    }
}
