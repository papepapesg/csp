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

    /**
     * Resolved inputs for this node (the engine resolves the handler's declared
     * input ports from data.inputMappings -> data.config -> port default before
     * the task runs). Prefer this over config()+var(): it already merged the
     * literal config and any data wires, so a handler reads one clean input map.
     *
     * @return array<string,mixed>
     */
    public function inputs(): array
    {
        return $this->task->variables['__inputs'] ?? [];
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $inputs = $this->task->variables['__inputs'] ?? [];

        return array_key_exists($key, $inputs) && $inputs[$key] !== null ? $inputs[$key] : $default;
    }
}
