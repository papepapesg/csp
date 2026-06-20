<?php

namespace Modules\Rbac\Http\Middleware;

use App\Foundation\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Modules\Rbac\Services\RbacScopeService;
use Symfony\Component\HttpFoundation\Response;

/**
 * EM-CFG-03 §8.5 data-scope gate: after JWT + permission, a scope-sensitive route verifies the
 * target entity falls within one of the caller's active scopes. Declared per route as
 * `scope:{scopeType},{requestKey}` (e.g. scope:TECH_REGION,tech_region_id) — the value is read
 * from the route params or body. A GLOBAL/OPERATOR scope or SUPER_ADMIN bypasses (in the
 * service). When the request carries no scoped value there is nothing to gate.
 */
class EnforceScope
{
    public function __construct(private readonly RbacScopeService $scopes) {}

    public function handle(Request $request, Closure $next, string $scopeType, string $requestKey): Response
    {
        $user = $request->user();
        $value = $request->route($requestKey) ?? $request->input($requestKey);
        if (! $user || $value === null || $value === '') {
            return $next($request); // auth/permission middleware owns the no-principal case
        }

        if (! $this->scopes->withinScope($user->uid, $scopeType, (string) $value)) {
            return ApiResponse::error('OUT_OF_SCOPE', "You are not scoped to {$scopeType} {$value}.", 403);
        }

        return $next($request);
    }
}
