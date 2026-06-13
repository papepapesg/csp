<?php

namespace App\Foundation\Support;

/**
 * Request-scoped ambient context: correlation id and operator scope.
 *
 * Operator resolution is TENANCY-SAFE and order-independent. It is resolved lazily,
 * preferring the AUTHENTICATED principal's own operator (evaluated when a controller,
 * query scope or service actually reads it — i.e. after auth has run), so a client can
 * never widen its scope with an X-Operator-Code header or ?operatorCode= param. The
 * header is honoured only for principals holding platform.cross_operator, and only as a
 * fallback for unauthenticated service/channel endpoints (USSD gateway, payment callbacks).
 * An explicit setOperatorCode() (seeders, workers, audited cross-operator code) overrides all.
 */
final class Context
{
    private static ?string $correlationId = null;

    /** Explicit/forced operator (seeders, workers, audited code) — wins over lazy resolution. */
    private static ?string $operatorCode = null;

    public static function setCorrelationId(string $id): void
    {
        self::$correlationId = $id;
    }

    public static function correlationId(): string
    {
        return self::$correlationId ??= Id::correlation();
    }

    public static function setOperatorCode(?string $code): void
    {
        self::$operatorCode = $code;
    }

    public static function operatorCode(): string
    {
        if (self::$operatorCode !== null) {
            return self::$operatorCode;
        }

        $user = self::resolveUser();
        $header = self::header();

        if ($user) {
            $own = $user->operator_code ?? null;
            // Entitled cross-operator access is the only way the header can widen scope.
            if ($header && $header !== $own
                && method_exists($user, 'can') && $user->can('platform.cross_operator')) {
                return $header;
            }

            return (string) ($own ?: config('sophix.default_operator', 'WIK'));
        }

        // No principal: unauthenticated service/channel endpoints fall back to the header/default.
        return (string) ($header ?: config('sophix.default_operator', 'WIK'));
    }

    public static function reset(): void
    {
        self::$correlationId = null;
        self::$operatorCode = null;
    }

    private static function resolveUser(): mixed
    {
        try {
            // Use the request's resolved principal (the same path controllers use, post-auth),
            // falling back to the default guard. No named 'sanctum' guard exists to query directly.
            return request()?->user() ?? auth()->user();
        } catch (\Throwable) {
            return null; // no auth context (console/queue) — treat as unauthenticated
        }
    }

    private static function header(): ?string
    {
        try {
            return request()?->header('X-Operator-Code');
        } catch (\Throwable) {
            return null;
        }
    }
}
