<?php

namespace App\Foundation\Support;

/**
 * Request-scoped ambient context: correlation id and operator scope.
 *
 * Populated by the CorrelationId and ResolveOperatorContext middleware and read
 * anywhere downstream (responses, events, audit, outbox) without threading the
 * request object through every layer.
 */
final class Context
{
    private static ?string $correlationId = null;

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
        return self::$operatorCode ?? (string) config('sophix.default_operator', 'WIK');
    }

    public static function reset(): void
    {
        self::$correlationId = null;
        self::$operatorCode = null;
    }
}
