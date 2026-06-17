<?php

namespace App\Foundation\Auth;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Keycloak request guard (FOUNDATION_AUTH). Active when SOPHIX_AUTH_DRIVER=keycloak;
 * registered as a `viaRequest` driver by FoundationServiceProvider and bound to the
 * `sanctum` guard name, so every `auth:sanctum` route authenticates with Keycloak
 * tokens — no route change.
 *
 * Verifies the bearer JWT locally (RS256) and resolves the local user by
 * users.uid = the token `sub` (the only Keycloak-aware column). Authorization is
 * unchanged: the resolved user already carries its Spatie roles + RBAC scope, so
 * permission/scope checks run exactly as before.
 */
class KeycloakGuard
{
    public function resolve(Request $request): ?Authenticatable
    {
        $jwt = $request->bearerToken();
        if (! $jwt) {
            return null;
        }

        $claims = KeycloakJwt::verify($jwt, (array) config('sophix.auth.keycloak'));
        $subject = $claims['sub'] ?? null;
        if (! $subject) {
            return null;
        }

        // Identity bridge: the Keycloak subject is the local users.uid. Users are
        // provisioned (with their roles/scope) out of band, so this is a lookup,
        // not a JIT create — an unknown subject is simply unauthenticated.
        return User::query()->where('uid', $subject)->first();
    }
}
