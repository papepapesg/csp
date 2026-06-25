<?php

namespace Modules\Workflow\Console;

use Illuminate\Console\Command;
use Modules\Workflow\Models\ActivityLog;
use Modules\Workflow\Models\ExternalTask;
use Modules\Workflow\Models\MessageSubscription;
use Modules\Workflow\Models\ProcessInstance;
use Modules\Workflow\Models\UserTask;
use Modules\Workflow\Models\WorkflowTimer;

/**
 * Ops review: show one process instance's live state and its tokens (read-only) —
 * status, active nodes, pending service/user tasks, timers, message-catch
 * subscriptions and the recent engine activity trace, so ops can see exactly
 * where a flow is parked before touching anything.
 */
class InstanceShowCommand extends Command
{
    protected $signature = 'sophix:workflow:instance-show {instance : The instance_id (pi_...)} {--trace=15 : Number of recent activity-log rows to show}';

    protected $description = 'Review: show a process instance\'s state and tokens (read-only)';

    public function handle(): int
    {
        $instanceId = (string) $this->argument('instance');
        $instance = ProcessInstance::query()->whereKey($instanceId)->first();
        if (! $instance) {
            $this->warn("No process instance {$instanceId}.");

            return self::SUCCESS;
        }

        $this->info("Process instance {$instance->instance_id}");
        $this->table(['Field', 'Value'], [
            ['process_key', $instance->process_key],
            ['definition_id', $instance->definition_id.' v'.$instance->definition_version],
            ['operator_code', (string) $instance->operator_code],
            ['business_key', (string) $instance->business_key],
            ['status', $instance->status],
            ['active_nodes', implode(', ', (array) ($instance->active_nodes ?? [])) ?: '—'],
            ['correlation_id', (string) $instance->correlation_id],
            ['error_message', (string) $instance->error_message],
            ['started_at', (string) $instance->started_at],
            ['ended_at', (string) $instance->ended_at],
        ]);

        if (in_array($instance->status, [ProcessInstance::COMPLETED, ProcessInstance::FAILED, ProcessInstance::CANCELLED], true)) {
            $this->warn("Status {$instance->status} — this instance is terminal; the engine will not advance it.");
        }

        $extTasks = ExternalTask::query()->where('instance_id', $instanceId)->orderBy('created_at')->get();
        if ($extTasks->isNotEmpty()) {
            $this->line('External tasks:');
            $this->table(
                ['task_id', 'node_id', 'topic', 'status', 'retries', 'worker_id', 'locked_until', 'error_message'],
                $extTasks->map(fn (ExternalTask $t) => [
                    $t->task_id, $t->node_id, $t->topic, $t->status, $t->retries,
                    (string) $t->worker_id, (string) $t->locked_until, (string) $t->error_message,
                ])->all()
            );
        }

        $userTasks = UserTask::query()->where('instance_id', $instanceId)->orderBy('created_at')->get();
        if ($userTasks->isNotEmpty()) {
            $this->line('User tasks:');
            $this->table(
                ['task_id', 'node_id', 'name', 'status', 'candidate_group', 'assignee', 'due_at'],
                $userTasks->map(fn (UserTask $t) => [
                    $t->task_id, $t->node_id, $t->name, $t->status,
                    (string) $t->candidate_group, (string) $t->assignee, (string) $t->due_at,
                ])->all()
            );
        }

        $timers = WorkflowTimer::query()->where('instance_id', $instanceId)->orderBy('fire_at')->get();
        if ($timers->isNotEmpty()) {
            $this->line('Timers:');
            $this->table(
                ['node_id', 'status', 'fire_at'],
                $timers->map(fn (WorkflowTimer $t) => [$t->node_id, $t->status, (string) $t->fire_at])->all()
            );
        }

        $subs = MessageSubscription::query()->where('instance_id', $instanceId)->get();
        if ($subs->isNotEmpty()) {
            $this->line('Message subscriptions:');
            $this->table(
                ['node_id', 'message_name', 'correlation_key'],
                $subs->map(fn (MessageSubscription $s) => [$s->node_id, $s->message_name, (string) $s->correlation_key])->all()
            );
        }

        $limit = max(1, (int) $this->option('trace'));
        $trace = ActivityLog::query()->where('instance_id', $instanceId)->orderByDesc('created_at')->limit($limit)->get();
        if ($trace->isNotEmpty()) {
            $this->line("Recent activity (latest {$limit}):");
            $this->table(
                ['created_at', 'event', 'node_id', 'node_type'],
                $trace->map(fn (ActivityLog $a) => [(string) $a->created_at, $a->event, (string) $a->node_id, (string) $a->node_type])->all()
            );
        }

        return self::SUCCESS;
    }
}
