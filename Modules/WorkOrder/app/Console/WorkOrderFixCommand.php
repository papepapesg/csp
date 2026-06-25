<?php

namespace Modules\WorkOrder\Console;

use Modules\WorkOrder\Models\WorkOrder;
use Illuminate\Console\Command;
use Modules\WorkOrder\Services\WorkOrderService;

/**
 * Ops safe-correction: drive one work order through the EXISTING WorkOrderService
 * lifecycle operations (the same methods behind the WO API) for break-glass
 * console use by ops with shell access. These wrap the service directly, so they
 * bypass route permissions but still go through the state machine + audit history.
 * Destructive actions (cancel) require --confirm.
 */
class WorkOrderFixCommand extends Command
{
    protected $signature = 'sophix:workorder:fix
        {work_order : The work_order_id}
        {action : auto-assign|reassign|start|cancel}
        {--actor=cli-ops : Recorded as the acting user on the audit history}
        {--reason= : Reason text, for reassign/cancel}
        {--contractor= : contractor_id, for reassign}
        {--team= : team_id, for reassign}
        {--technician= : assigned_technician_id, for reassign}
        {--confirm : Required for destructive actions (cancel)}';

    protected $description = 'Safe-correction: run a work-order lifecycle op for one WO (auto-assign, reassign, start, cancel)';

    private const DESTRUCTIVE = ['cancel'];

    public function handle(WorkOrderService $service): int
    {
        $id = (string) $this->argument('work_order');
        $action = (string) $this->argument('action');
        $actor = (string) $this->option('actor');
        $reason = $this->option('reason') !== null ? (string) $this->option('reason') : null;

        if (in_array($action, self::DESTRUCTIVE, true) && ! $this->option('confirm')) {
            $this->error("Action '{$action}' is destructive; re-run with --confirm.");

            return self::FAILURE;
        }

        $wo = WorkOrder::query()->where('work_order_id', $id)->first();
        if (! $wo) {
            $this->error("No work order {$id}.");

            return self::FAILURE;
        }

        match ($action) {
            'auto-assign' => $this->autoAssign($service, $wo, $actor),
            'reassign' => $service->reassign($wo, array_filter([
                'contractor_id' => $this->option('contractor'),
                'team_id' => $this->option('team'),
                'assigned_technician_id' => $this->option('technician'),
            ], fn ($v) => $v !== null), $reason, $actor),
            'start' => $service->start($wo, $actor),
            'cancel' => $service->cancel($wo, $reason, $actor),
            default => throw new \InvalidArgumentException("Unknown action '{$action}'."),
        };

        $this->info("workorder:fix: '{$action}' applied to {$id} by {$actor}.");
        $this->call('sophix:workorder:show', ['work_order' => $id]);

        return self::SUCCESS;
    }

    private function autoAssign(WorkOrderService $service, WorkOrder $wo, string $actor): void
    {
        $assigned = $service->autoAssign($wo, $actor);
        if (! $assigned) {
            $this->warn('auto-assign found no candidate — the work order stays PENDING for manual routing.');
        }
    }
}
