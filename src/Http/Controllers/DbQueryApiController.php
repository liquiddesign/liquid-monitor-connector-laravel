<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LiquidMonitorConnector\DbQuery\ReadOnlyQueryRunner;
use LiquidMonitorConnector\Http\Middleware\AuthorizeMonitorRequest;
use PDOException;

/**
 * JSON REST API for read-only database queries proxied through the connector.
 * The monitor sends SQL + connection credentials in the request body; this
 * controller connects via PDO and returns the result as flat JSON. Contract
 * matches `LiquidMonitorConnector\DbQuery\DbQueryApiPresenter` from the Nette
 * connector (same request/response shape, error codes and messages) so the
 * backend's `/api/context/connector-db/query` proxy needs no changes.
 *
 * Access is gated by {@see AuthorizeMonitorRequest}
 * (IP allowlist + mandatory token) rather than Nette's Tracy-debug-mode trick — the
 * database credentials themselves still travel in the request body, so no separate
 * secret is required for them.
 */
final class DbQueryApiController
{
    public function query(Request $request): JsonResponse
    {
        /** @var mixed $decoded */
        $decoded = $request->json()->all();

        if (! \is_array($decoded)) {
            return $this->error(400, 'Invalid JSON body.');
        }

        $sql = $decoded['sql'] ?? null;

        if (! \is_string($sql) || \trim($sql) === '') {
            return $this->error(422, 'Missing or empty "sql" field.');
        }

        /** @var mixed $rawConnection */
        $rawConnection = $decoded['connection'] ?? null;

        if (! \is_array($rawConnection)) {
            return $this->error(422, 'Missing "connection" object.');
        }

        foreach (['host', 'database', 'username', 'driver'] as $field) {
            $value = $rawConnection[$field] ?? null;

            if (! \is_string($value) || \trim($value) === '') {
                return $this->error(422, "Missing required connection field: {$field}.");
            }
        }

        /** @var array{driver: string, host: string, port?: int|null, database: string, username: string, password?: string|null} $connection */
        $connection = $rawConnection;

        $rowLimit = self::intOrDefault($decoded['row_limit'] ?? null, 100);
        $statementTimeout = self::intOrDefault($decoded['statement_timeout_seconds'] ?? null, 5);

        try {
            $runner = new ReadOnlyQueryRunner($connection, $rowLimit, $statementTimeout);
            $result = $runner->run($sql);
        } catch (InvalidArgumentException $e) {
            return $this->error(422, $e->getMessage());
        } catch (PDOException) {
            return $this->error(500, 'Database query failed.');
        }

        return response()->json($result);
    }

    private function error(int $code, string $message): JsonResponse
    {
        return response()->json(['error' => $message, 'code' => $code], $code);
    }

    private static function intOrDefault(mixed $value, int $default): int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && \ctype_digit($value)) {
            return (int) $value;
        }

        return $default;
    }
}
