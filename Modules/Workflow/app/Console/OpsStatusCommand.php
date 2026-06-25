<?php

namespace Modules\Workflow\Console;

use Illuminate\Console\Command;
use Modules\Workflow\Models\ExternalTask;
use Modules\Workflow\Models\ProcessInstance;
use Modules\Workflow\Models\UserTask;
use Modules\Workflow\Models\WorkflowTimer;

/**
 * Ops review: a one-glance health summary of the workflow-engine work queues an
 * operator needs to drain (read-only) — stuck/failed instances, task incidents,
 * expired locks and overdue timers/user-tasks. Mirrors the Billing ops-status
 * runbook style (FOUNDATION_CAMUNDA job-executor health).
 */
class OpsStatusCommand extends Command
{
    protected $signature = 'sophix:workflow:ops-status {--operator= : Scope to one operator_code (default: all)}';

    protected $description = 'Review: counts of workflow items needing ops attention (read-only)';

    public function handle(): int
    {
        $op = $this->option('operator');
        $scope = fn ($q) => $op ? $q->where('operator_code', $op) : $q;

        $rows = [
            ['Instances: running', $scope(ProcessInstance::query())->where('status', ProcessInstance::RUNNING)->count()],
            ['Instances: failed (incident)', $scope(ProcessInstance::query())->where('status', ProcessInstance::FAILED)->count()],
            ['Instances: suspended', $scope(ProcessInstance::query())->where('status', ProcessInstance::SUSPENDED)->count()],
            ['External tasks: created (awaiting worker)', $scope(ExternalTask::query())->where('status', ExternalTask::CREATED)->count()],
            ['External tasks: locks expired (re-pollable)', $scope(ExternalTask::query())->where('status', ExternalTask::LOCKED)->where('locked_until', '<', now())->count()],
            ['External tasks: incident', $scope(ExternalTask::query())->where('status', ExternalTask::INCIDENT)->count()],
            ['External tasks: failed', $scope(ExternalTask::query())->where('status', ExternalTask::FAILED)->count()],
            ['Timers: due to fire (PENDING, past fire_at)', WorkflowTimer::query()->where('status', 'PENDING')->where('fire_at', '<=', now())->count()],
            ['User tasks: open', $scope(UserTask::query())->where('status', UserTask::OPEN)->count()],
            ['User tasks: open and overdue', $scope(UserTask::query())->where('status', UserTask::OPEN)->whereNotNull('due_at')->where('due_at', '<', now())->count()],
        ];

        $this->info('Workflow ops status'.($op ? " — operator {$op}" : ' — all operators'));
        $this->table(['Queue', 'Count'], $rows);
        $this->line('Drain hints: timers/locks → sophix:workflow:tick · tasks → sophix:workflow:work · inspect → sophix:workflow:instance-show <instance_id>');

        return self::SUCCESS;
    }
}
