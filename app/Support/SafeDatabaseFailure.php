<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

final class SafeDatabaseFailure
{
    public static function report(QueryException $exception): void
    {
        // Neither exception objects, driver messages nor SQL bindings are safe log context.
        preg_match('/^\s*(\w+)/', $exception->getSql(), $operation);
        $verb = strtolower($operation[1] ?? 'unknown');
        $correlationId = request()->attributes->get('task_correlation_id');
        Log::error('Database operation failed', [
            'connection' => $exception->getConnectionName(),
            'sqlstate' => $exception->errorInfo[0] ?? (string) $exception->getCode(),
            'driver_code' => $exception->errorInfo[1] ?? null,
            'operation' => in_array($verb, ['select', 'insert', 'update', 'delete', 'alter', 'create', 'drop', 'truncate'], true) ? $verb : 'unknown',
            'query_fingerprint' => hash('sha256', $exception->getSql()),
            'exception_class' => $exception::class,
            'correlation_id' => is_string($correlationId) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $correlationId) === 1 ? $correlationId : null,
            'frames' => array_map(fn (array $frame) => array_intersect_key($frame, array_flip(['file', 'line', 'class', 'function'])), array_slice($exception->getTrace(), 0, 12)),
        ]);
    }
}
