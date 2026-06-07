<?php

namespace Modules\Workflow\Contracts;

use Modules\Workflow\Models\ExternalTask;
use Modules\Workflow\Models\ProcessInstance;

/**
 * Context handed to a TaskHandler when a service task runs. Carries the process
 * variables (ids + flags), business key and operator scope. Handlers read inputs
 * from variables and return outputs to merge back (CAM-START-4).
 */
class TaskContext
{
    public function __construct(
        public readonly ProcessInstance $instance,
        public readonly ExternalTask $task,
    ) {}

    /** @return array<string,mixed> */
    public function variables(): array
    {
        return $this->instance->variables ?? [];
    }

    public function var(string $key, mixed $default = null): mixed
    {
        return data_get($this->instance->variables ?? [], $key, $default);
    }

    public function businessKey(): ?string
    {
        return $this->instance->business_key;
    }

    public function operatorCode(): ?string
    {
        return $this->instance->operator_code;
    }

    /** @return array<string,mixed> node-level config from the flow graph (data.config) */
    public function config(): array
    {
        return $this->task->variables['__config'] ?? [];
    }
}
