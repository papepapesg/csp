<?php

namespace Modules\Workflow\Engine;

use App\Foundation\Errors\DomainException;
use App\Foundation\Support\Context;
use Illuminate\Support\Facades\DB;
use Modules\Workflow\Models\ActivityLog;
use Modules\Workflow\Models\ExternalTask;
use Modules\Workflow\Models\MessageSubscription;
use Modules\Workflow\Models\ProcessDefinition;
use Modules\Workflow\Models\ProcessInstance;
use Modules\Workflow\Models\UserTask;
use Modules\Workflow\Models\WorkflowTimer;

/**
 * Config-driven workflow engine (FOUNDATION_CAMUNDA, Laravel-native).
 *
 * Executes a process_definition graph as a single-token flow. Service tasks are
 * handed to module workers via external tasks (the engine never runs business
 * logic itself); gateways branch on variables; timers/messages park the
 * instance until fired/correlated. All flow shape lives in DATA, so behaviour
 * varies by operator with zero code change.
 */
class WorkflowEngine
{
    /**
     * Resolve the active definition for a process key, preferring an
     * operator-specific deployment over the global default, highest version.
     */
    public function resolveDefinition(string $processKey, ?string $operator): ProcessDefinition
    {
        $definition = ProcessDefinition::query()
            ->where('process_key', $processKey)
            ->where('status', ProcessDefinition::DEPLOYED)
            ->where(fn ($q) => $q->where('operator_code', $operator)->orWhereNull('operator_code'))
            ->orderByRaw('operator_code IS NULL')   // operator-specific first
            ->orderByDesc('version')
            ->first();

        if (! $definition) {
            throw DomainException::ruleRejected('PROCESS_NOT_DEPLOYED', "No deployed process [{$processKey}] for operator [{$operator}].");
        }

        return $definition;
    }

    /**
     * Start a new instance (CAM-START-*). Returns the instance; it will be
     * RUNNING (parked on a wait node) or already COMPLETED for trivial flows.
     *
     * @param  array<string,mixed>  $variables
     */
    public function start(string $processKey, ?string $businessKey, array $variables = [], ?string $operator = null): ProcessInstance
    {
        $operator ??= Context::operatorCode();
        $definition = $this->resolveDefinition($processKey, $operator);

        $start = $definition->startNode();
        if (! $start) {
            throw DomainException::ruleRejected('PROCESS_NO_START', "Process [{$processKey}] has no start event.");
        }

        $instance = ProcessInstance::query()->create([
            'definition_id' => $definition->definition_id,
            'process_key' => $definition->process_key,
            'definition_version' => $definition->version,
            'operator_code' => $operator,
            'business_key' => $businessKey,
            'variables' => $variables,
            'status' => ProcessInstance::RUNNING,
            'correlation_id' => Context::correlationId(),
            'started_at' => now(),
        ]);

        $this->log($instance, $start['id'] ?? null, 'startEvent', 'INSTANCE_STARTED', ['businessKey' => $businessKey]);

        $this->advanceFrom($instance, $definition, $start['id']);

        return $instance->refresh();
    }

    /**
     * Walk outgoing edges from $nodeId and enter the next node(s). Single token:
     * for gateways we pick exactly one matching edge.
     */
    private function advanceFrom(ProcessInstance $instance, ProcessDefinition $definition, string $nodeId): void
    {
        $edges = $definition->outgoing($nodeId);
        if (empty($edges)) {
            return; // dead end without an end event — leave parked
        }

        $next = $this->selectEdge($instance, $edges);
        if (! $next) {
            throw DomainException::ruleRejected('NO_MATCHING_PATH', "No matching outgoing path from node [{$nodeId}].");
        }

        $target = $definition->node($next['target']);
        if (! $target) {
            throw DomainException::ruleRejected('BAD_EDGE', "Edge points to unknown node [{$next['target']}].");
        }

        $this->enterNode($instance, $definition, $target);
    }

    /**
     * Enter a node: wait nodes create work items and park the instance;
     * pass-through nodes (gateway) and end events advance immediately.
     */
    private function enterNode(ProcessInstance $instance, ProcessDefinition $definition, array $node): void
    {
        $type = $node['type'] ?? 'serviceTask';
        $nodeId = $node['id'];
        $cfg = $node['data']['config'] ?? [];
        $this->log($instance, $nodeId, $type, 'NODE_ENTER');

        switch ($type) {
            case 'serviceTask':
                $topic = $node['data']['topic'] ?? null;
                if (! $topic) {
                    throw DomainException::ruleRejected('NODE_NO_TOPIC', "Service task [{$nodeId}] has no topic.");
                }
                $task = ExternalTask::query()->create([
                    'instance_id' => $instance->instance_id,
                    'node_id' => $nodeId,
                    'topic' => $topic,
                    'operator_code' => $instance->operator_code,
                    'business_key' => $instance->business_key,
                    'variables' => ['__config' => $cfg],
                    'status' => ExternalTask::CREATED,
                    'retries' => (int) ($cfg['retries'] ?? 3),
                ]);
                $this->park($instance, $nodeId);
                $this->log($instance, $nodeId, $type, 'TASK_CREATED', ['taskId' => $task->task_id, 'topic' => $topic]);
                break;

            case 'userTask':
                $task = UserTask::query()->create([
                    'instance_id' => $instance->instance_id,
                    'node_id' => $nodeId,
                    'name' => $node['data']['name'] ?? $nodeId,
                    'candidate_group' => $node['data']['candidateGroup'] ?? null,
                    'variables' => ['__config' => $cfg],
                    'status' => UserTask::OPEN,
                    'due_at' => isset($cfg['dueInMinutes']) ? now()->addMinutes((int) $cfg['dueInMinutes']) : null,
                ]);
                $this->park($instance, $nodeId);
                $this->log($instance, $nodeId, $type, 'USER_TASK_CREATED', ['taskId' => $task->task_id]);
                break;

            case 'exclusiveGateway':
                // Pure routing node — choose one outgoing edge and continue.
                $this->advanceFrom($instance, $definition, $nodeId);
                break;

            case 'timer':
                WorkflowTimer::query()->create([
                    'instance_id' => $instance->instance_id,
                    'node_id' => $nodeId,
                    'fire_at' => now()->addSeconds((int) ($cfg['durationSeconds'] ?? 60)),
                    'status' => 'PENDING',
                ]);
                $this->park($instance, $nodeId);
                break;

            case 'messageCatch':
                MessageSubscription::query()->create([
                    'instance_id' => $instance->instance_id,
                    'node_id' => $nodeId,
                    'message_name' => $node['data']['messageName'] ?? $nodeId,
                    'correlation_key' => $instance->business_key,
                ]);
                $this->park($instance, $nodeId);
                break;

            case 'endEvent':
                $instance->update([
                    'status' => ProcessInstance::COMPLETED,
                    'active_nodes' => [],
                    'ended_at' => now(),
                ]);
                $this->log($instance, $nodeId, $type, 'INSTANCE_COMPLETED');
                ProcessInstanceEnded::dispatch($instance->refresh());
                break;

            default:
                throw DomainException::ruleRejected('UNKNOWN_NODE_TYPE', "Unknown node type [{$type}].");
        }
    }

    /**
     * Select one outgoing edge: first non-default edge whose condition matches,
     * else the default edge.
     *
     * @param  array<int,array<string,mixed>>  $edges
     * @return array<string,mixed>|null
     */
    private function selectEdge(ProcessInstance $instance, array $edges): ?array
    {
        $default = null;
        foreach ($edges as $edge) {
            $data = $edge['data'] ?? [];
            if (($data['default'] ?? false) === true) {
                $default = $edge;

                continue;
            }
            if (! isset($data['condition'])) {
                // unconditional edge acts as the path when there is only one
                if (count($edges) === 1) {
                    return $edge;
                }
                $default ??= $edge;

                continue;
            }
            if ($this->evaluateCondition($instance->variables ?? [], $data['condition'])) {
                return $edge;
            }
        }

        return $default;
    }

    /**
     * Structured, side-effect-free condition: {var, op, value}.
     *
     * @param  array<string,mixed>  $vars
     * @param  array<string,mixed>  $condition
     */
    private function evaluateCondition(array $vars, array $condition): bool
    {
        $actual = data_get($vars, $condition['var'] ?? '');
        $expected = $condition['value'] ?? null;

        return match ($condition['op'] ?? 'eq') {
            'eq' => $actual == $expected,
            'neq' => $actual != $expected,
            'gt' => $actual > $expected,
            'gte' => $actual >= $expected,
            'lt' => $actual < $expected,
            'lte' => $actual <= $expected,
            'truthy' => (bool) $actual === true,
            'falsy' => (bool) $actual === false,
            'in' => is_array($expected) && in_array($actual, $expected, true),
            default => false,
        };
    }

    private function park(ProcessInstance $instance, string $nodeId): void
    {
        $instance->update(['active_nodes' => [$nodeId], 'status' => ProcessInstance::RUNNING]);
    }

    /** @param array<string,mixed> $data */
    private function log(ProcessInstance $instance, ?string $nodeId, ?string $nodeType, string $event, array $data = []): void
    {
        ActivityLog::query()->create([
            'instance_id' => $instance->instance_id,
            'node_id' => $nodeId,
            'node_type' => $nodeType,
            'event' => $event,
            'data' => $data ?: null,
            'created_at' => now(),
        ]);
    }

    // ---- Continuation entry points (called by workers / APIs / scheduler) ----

    /**
     * Complete a service task: merge outputs, route the flow on. A rejected
     * result sets __rejected so a gateway can branch to the rejection path.
     *
     * @param  array<string,mixed>  $outputs
     */
    public function completeExternalTask(ExternalTask $task, array $outputs = []): void
    {
        DB::transaction(function () use ($task, $outputs) {
            $instance = ProcessInstance::query()->whereKey($task->instance_id)->lockForUpdate()->firstOrFail();
            $task->update(['status' => ExternalTask::COMPLETED, 'completed_at' => now()]);
            $this->mergeVariables($instance, $outputs);
            $this->log($instance, $task->node_id, 'serviceTask', 'TASK_COMPLETED', $outputs);

            $definition = ProcessDefinition::query()->findOrFail($instance->definition_id);
            $this->advanceFrom($instance, $definition, $task->node_id);
        });
    }

    public function failExternalTask(ExternalTask $task, string $message, bool $retryable = true): void
    {
        $remaining = max(0, $task->retries - 1);
        if ($retryable && $remaining > 0) {
            $task->update(['status' => ExternalTask::CREATED, 'retries' => $remaining, 'error_message' => $message, 'worker_id' => null, 'locked_until' => null]);

            return;
        }

        DB::transaction(function () use ($task, $message) {
            $task->update(['status' => ExternalTask::INCIDENT, 'retries' => 0, 'error_message' => $message]);
            $instance = ProcessInstance::query()->whereKey($task->instance_id)->lockForUpdate()->firstOrFail();
            $instance->update(['status' => ProcessInstance::FAILED, 'error_message' => $message, 'ended_at' => now()]);
            $this->log($instance, $task->node_id, 'serviceTask', 'INCIDENT', ['error' => $message]);
            ProcessInstanceEnded::dispatch($instance->refresh());
        });
    }

    /** @param array<string,mixed> $outputs */
    public function completeUserTask(UserTask $task, array $outputs = []): void
    {
        DB::transaction(function () use ($task, $outputs) {
            $instance = ProcessInstance::query()->whereKey($task->instance_id)->lockForUpdate()->firstOrFail();
            $task->update(['status' => UserTask::COMPLETED, 'completed_at' => now()]);
            $this->mergeVariables($instance, $outputs);
            $this->log($instance, $task->node_id, 'userTask', 'USER_TASK_COMPLETED', $outputs);

            $definition = ProcessDefinition::query()->findOrFail($instance->definition_id);
            $this->advanceFrom($instance, $definition, $task->node_id);
        });
    }

    /** @param array<string,mixed> $variables */
    public function correlateMessage(string $messageName, ?string $correlationKey, array $variables = []): int
    {
        $subs = MessageSubscription::query()
            ->where('message_name', $messageName)
            ->when($correlationKey, fn ($q) => $q->where('correlation_key', $correlationKey))
            ->get();

        foreach ($subs as $sub) {
            DB::transaction(function () use ($sub, $variables) {
                $instance = ProcessInstance::query()->whereKey($sub->instance_id)->lockForUpdate()->first();
                if (! $instance || $instance->status !== ProcessInstance::RUNNING) {
                    return;
                }
                $this->mergeVariables($instance, $variables);
                $definition = ProcessDefinition::query()->findOrFail($instance->definition_id);
                $node = $sub->node_id;
                $sub->delete();
                $this->advanceFrom($instance, $definition, $node);
            });
        }

        return $subs->count();
    }

    public function fireDueTimers(): int
    {
        $timers = WorkflowTimer::query()->where('status', 'PENDING')->where('fire_at', '<=', now())->get();
        foreach ($timers as $timer) {
            DB::transaction(function () use ($timer) {
                $instance = ProcessInstance::query()->whereKey($timer->instance_id)->lockForUpdate()->first();
                $timer->update(['status' => 'FIRED']);
                if (! $instance || $instance->status !== ProcessInstance::RUNNING) {
                    return;
                }
                $definition = ProcessDefinition::query()->findOrFail($instance->definition_id);
                $this->advanceFrom($instance, $definition, $timer->node_id);
            });
        }

        return $timers->count();
    }

    /** @param array<string,mixed> $outputs */
    private function mergeVariables(ProcessInstance $instance, array $outputs): void
    {
        if (! empty($outputs)) {
            $instance->update(['variables' => array_merge($instance->variables ?? [], $outputs)]);
        }
    }
}
