<?php

namespace App\Foundation\Http\Middleware;

use App\Foundation\Support\Context;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Operator scope (DD_API-00 §5) is resolved LAZILY by App\Foundation\Support\Context — from the
 * authenticated principal after auth has run, never from client input. This middleware clears any
 * forced operator left in the (static) Context at the start of each request, so a value forced by a
 * prior request on a reused PHP-FPM worker can't leak into this one; lazy auth-derived resolution
 * then governs. Tenancy enforcement lives in Context + the BelongsToOperator global scope.
 */
class ResolveOperatorContext
{
    public function handle(Request $request, Closure $next): Response
    {
        Context::setOperatorCode(null);

        return $next($request);
    }
}
