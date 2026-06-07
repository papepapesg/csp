<?php

namespace App\Foundation\Http\Middleware;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generates or preserves the X-Correlation-Id header (DD_API-00 §5, §10).
 *
 * The gateway "generate if missing, preserve if present" rule is implemented
 * here so the id is available to every downstream module, event and log line.
 */
class CorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header('X-Correlation-Id') ?: Id::correlation();
        Context::setCorrelationId($correlationId);
        $request->headers->set('X-Correlation-Id', $correlationId);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Correlation-Id', $correlationId);

        return $response;
    }
}
