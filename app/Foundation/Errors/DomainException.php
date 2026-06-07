<?php

namespace App\Foundation\Errors;

use RuntimeException;
use Throwable;

/**
 * Base exception for business-rule rejections that map directly to the SOPHIX
 * standard error model (DD_API-00 §8).
 *
 * Throw this (or a subclass) from any module command/service to produce a
 * consistent error envelope with an errorCode, HTTP status, retryable flag,
 * optional field errors and a suggested nextAction.
 */
class DomainException extends RuntimeException
{
    /**
     * @param  array<int, array{field: string, code: string, message: string}>  $fieldErrors
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly bool $retryable = false,
        public readonly array $fieldErrors = [],
        public readonly ?string $nextAction = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notFound(string $message, ?string $nextAction = null): self
    {
        return new self(ErrorCode::NOT_FOUND, $message, 404, false, [], $nextAction);
    }

    public static function conflict(string $message, ?string $nextAction = null): self
    {
        return new self(ErrorCode::CONFLICT, $message, 409, false, [], $nextAction);
    }

    public static function ruleRejected(string $errorCode, string $message, ?string $nextAction = null): self
    {
        return new self($errorCode, $message, 422, false, [], $nextAction);
    }

    public static function dependencyUnavailable(string $message): self
    {
        return new self(ErrorCode::DEPENDENCY_UNAVAILABLE, $message, 503, true);
    }
}
