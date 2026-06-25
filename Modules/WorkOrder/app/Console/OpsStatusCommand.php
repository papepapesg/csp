<?php

namespace Modules\WorkOrder\Console;

use Illuminate\Console\Command;
use Modules\WorkOrder\Models\WorkOrder;

/**
 * Ops review: a one-glance health summary of the work-order queues an operator
 * needs to drain (read-only) — unassigned PENDING, in-flight, finalize-pending,
 * SLA-breached and escalation candidates.
 */
class OpsStatusCommand extends Command
{
    protected $signature = 'sophix:workorder:ops-status {--operator= : Scope to one operator code (default: all)}';

    protected $description = 'Review: counts of work orders needing ops attention (read-only)';

    public function handle(): int
    {
        $op = $this->option('operator');
        $scope = fn ($q) => $op ? $q->where('operator_code', $op) : $q;

        $unassigned = $scope(WorkOrder::query())
            ->where('status', WorkOrder::PENDING)
            ->whereNull('contractor_id')->whereNull('team_id')->whereNull('assigned_technician_id')
            ->count();

        $slaBreached = $scope(WorkOrder::query())
            ->whereIn('status', [WorkOrder::PENDING, WorkOrder::ASSIGNED, WorkOrder::IN_PROGRESS, WorkOrder::FINALIZATION_PENDING])
            ->whereNotNull('sla_due_at')->where('sla_due_at', '<', now())
            ->count();

        $rows = [
            ['Unassigned (PENDING, no contractor/team/tech)', $unassigned],
            ['PENDING (total)', $scope(WorkOrder::query())->where('status', WorkOrder::PENDING)->count()],
            ['ASSIGNED (not yet started)', $scope(WorkOrder::query())->where('status', WorkOrder::ASSIGNED)->count()],
            ['IN_PROGRESS', $scope(WorkOrder::query())->where('status', WorkOrder::IN_PROGRESS)->count()],
            ['FINALIZATION_PENDING (awaiting 2nd confirm)', $scope(WorkOrder::query())->where('status', WorkOrder::FINALIZATION_PENDING)->count()],
            ['SLA breached (open, past sla_due_at)', $slaBreached],
            ['Escalation candidates (open)', $scope(WorkOrder::query())->where('escalation_candidate', true)->whereNotIn('status', [WorkOrder::COMPLETED, WorkOrder::CANCELLED])->count()],
        ];

        $this->info('Work-order ops status'.($op ? " — operator {$op}" : ' — all operators'));
        $this->table(['Queue', 'Count'], $rows);
        $this->line('Drill in: sophix:workorder:show <work_order_id> · correct: sophix:workorder:fix <work_order_id> <action>');

        return self::SUCCESS;
    }
}
