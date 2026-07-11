<?php

namespace App\Foundation\Workflow;

use Modules\Workflow\Models\ProcessInstance;

/** Stable orchestration port consumed by capability modules. */
interface WorkflowRuntime
{
    /** @param array<string,mixed> $variables */
    public function start(string $processKey, string $businessKey, array $variables = [], ?string $operator = null): ProcessInstance;

    /** @param array<string,mixed> $variables */
    public function correlateMessage(string $messageName, ?string $correlationKey, array $variables = []): int;

    public function cancelByBusinessKey(string $businessKey, ?string $reason = null): int;
}
