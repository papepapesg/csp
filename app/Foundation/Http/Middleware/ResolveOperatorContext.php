<?php

namespace App\Foundation\Http\Middleware;

use App\Foundation\Support\Context;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the operator scope for the request (DD_API-00 §5, FE-APP-00 §4).
 *
 * Precedence: explicit X-Operator-Code header → authenticated user's operator
 * attribute → configured default. SOPHIX is multi-operator (WIK, WUG, WTZ,
 * YASSN, ...) and every operator-scoped query must honour this value.
 */
class ResolveOperatorContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $operator = $request->header('X-Operator-Code');

        if (! $operator && $request->user()) {
            $operator = $request->user()->operator_code ?? null;
        }

        Context::setOperatorCode($operator ?: config('sophix.default_operator'));

        return $next($request);
    }
}
