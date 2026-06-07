<?php

namespace Modules\Workflow\Contracts;

/**
 * Result of a service task. success() merges variables and routes the flow on;
 * fail() reports a (retryable) failure; reject() routes the BPMN to a business
 * rejection path via the returned variables (CAM-WORKER-6).
 */
class TaskResult
{
    /** @param array<string,mixed> $variables */
    private function __construct(
        public readonly bool $ok,
        public readonly array $variables = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly bool $retryable = true,
    ) {}

    /** @param array<string,mixed> $variables */
    public static function success(array $variables = []): self
    {
        return new self(true, $variables);
    }

    public static function fail(string $message, bool $retryable = true): self
    {
        return new self(false, [], 'TASK_FAILED', $message, $retryable);
    }

    /** Non-retryable business rejection that routes the flow (variables carry the decision). */
    public static function reject(string $code, array $variables = []): self
    {
        return new self(true, array_merge($variables, ['__rejected' => true, '__rejectCode' => $code]));
    }
}
