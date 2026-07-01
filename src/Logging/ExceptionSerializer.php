<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Logging;

/**
 * Serializes a Throwable (and its chain of previous exceptions) into the JSON-friendly
 * shape expected by the `data` field of `POST /api/connector/log`. Mirrors
 * `LiquidMonitorConnector\Tasks\ExceptionToJsonArray` from the Nette connector.
 */
final class ExceptionSerializer
{
    /**
     * @return array<int, string>
     */
    public static function traces(\Throwable $exception): array
    {
        return \array_map(
            static fn (string $line): string => \trim($line),
            \explode("\n", $exception->getTraceAsString()),
        );
    }

    /**
     * @return array{trace: array<int, string>, file: string, line: int, previousExceptions: array<int, array{message: string, trace: array<int, string>, file: string, line: int}>}
     */
    public static function toArray(\Throwable $exception): array
    {
        $previousExceptions = [];
        $current = $exception;

        while ($current = $current->getPrevious()) {
            $previousExceptions[] = [
                'message' => $current->getMessage(),
                'trace' => self::traces($current),
                'file' => $current->getFile(),
                'line' => $current->getLine(),
            ];
        }

        return [
            'trace' => self::traces($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'previousExceptions' => $previousExceptions,
        ];
    }
}
