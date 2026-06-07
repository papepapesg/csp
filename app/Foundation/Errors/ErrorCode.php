<?php

namespace App\Foundation\Errors;

/**
 * Canonical, cross-module error codes (DD_API-00 §8).
 *
 * Modules may define their own domain-specific codes, but the foundation owns
 * the shared transport-level codes used by middleware and the exception renderer.
 */
final class ErrorCode
{
    public const VALIDATION_FAILED = 'VALIDATION_FAILED';

    public const UNAUTHENTICATED = 'UNAUTHENTICATED';

    public const FORBIDDEN = 'FORBIDDEN';

    public const NOT_FOUND = 'NOT_FOUND';

    public const CONFLICT = 'CONFLICT';

    public const IDEMPOTENCY_CONFLICT = 'IDEMPOTENCY_CONFLICT';

    public const BUSINESS_RULE_REJECTED = 'BUSINESS_RULE_REJECTED';

    public const RATE_LIMITED = 'RATE_LIMITED';

    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    public const DEPENDENCY_UNAVAILABLE = 'DEPENDENCY_UNAVAILABLE';

    public const METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';
}
