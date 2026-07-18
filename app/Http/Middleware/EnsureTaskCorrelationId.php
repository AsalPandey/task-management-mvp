<?php

namespace App\Http\Middleware;

use App\Support\UlidGenerator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTaskCorrelationId
{
    public const HEADER = 'X-Correlation-ID';

    public const REQUEST_ATTRIBUTE = 'task_correlation_id';

    public function __construct(private readonly UlidGenerator $ulids) {}

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);
        $correlationId = $this->isValid($incoming) ? $incoming : $this->ulids->generate();

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $correlationId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }

    private function isValid(?string $correlationId): bool
    {
        return $correlationId !== null
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $correlationId) === 1;
    }
}
