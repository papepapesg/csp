<?php

namespace App\Foundation\Support;

use Illuminate\Support\Str;

/**
 * Prefixed-ULID identifier generator.
 *
 * SOPHIX API contracts use human-traceable, type-prefixed identifiers such as
 * `sub_01HY...`, `op_01J...`, `cust_01J...` (see DD_API-00 §7). A ULID gives us
 * lexicographically sortable, time-ordered, globally unique ids without exposing
 * sequential database keys across module boundaries.
 */
final class Id
{
    public static function make(string $prefix): string
    {
        return $prefix.'_'.strtolower((string) Str::ulid());
    }

    public static function customer(): string
    {
        return self::make('cust');
    }

    public static function account(): string
    {
        return self::make('acct');
    }

    public static function subscription(): string
    {
        return self::make('sub');
    }

    public static function operation(): string
    {
        return self::make('op');
    }

    public static function correlation(): string
    {
        return self::make('corr');
    }
}
