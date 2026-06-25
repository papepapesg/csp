<?php

namespace Modules\Provisioning\Console;

use Illuminate\Console\Command;
use Modules\Provisioning\Models\ProvisioningCommand;
use Modules\Provisioning\Models\ProvisioningForceSyncRequest;
use Modules\Provisioning\Models\ProvisioningReconciliationItem;

/**
 * Ops review: a one-glance health summary of the provisioning work queues NOC
 * needs to drain (read-only) — in-flight async commands, failures, open
 * reconciliation mismatches and force-sync requests awaiting approval.
 */
class OpsStatusCommand extends Command
{
    protected $signature = 'sophix:provisioning:ops-status {--operator= : Scope to one operator code (default: all)}';

    protected $description = 'Review: counts of provisioning items needing ops attention (read-only)';

    public function handle(): int
    {
        $op = $this->option('operator');
        $scope = fn ($q) => $op ? $q->where('operator_code', $op) : $q;

        $rows = [
            ['Commands: pending dispatch', $scope(ProvisioningCommand::query())->where('status', ProvisioningCommand::PENDING)->count()],
            ['Commands: sent (awaiting outcome)', $scope(ProvisioningCommand::query())->where('status', ProvisioningCommand::SENT)->count()],
            ['Commands: accepted async (in-flight)', $scope(ProvisioningCommand::query())->where('status', ProvisioningCommand::ACCEPTED)->count()],
            ['Commands: failed', $scope(ProvisioningCommand::query())->where('status', ProvisioningCommand::FAILED)->count()],
            ['Commands: reconcile mismatch', $scope(ProvisioningCommand::query())->where('status', ProvisioningCommand::MISMATCH)->count()],
            ['Reconciliation items: open', $scope(ProvisioningReconciliationItem::query())->where('status', ProvisioningReconciliationItem::OPEN)->count()],
            ['Reconciliation items: in review', $scope(ProvisioningReconciliationItem::query())->where('status', ProvisioningReconciliationItem::IN_REVIEW)->count()],
            ['Force-sync: awaiting approval', $scope(ProvisioningForceSyncRequest::query())->where('status', ProvisioningForceSyncRequest::PENDING_APPROVAL)->count()],
            ['Force-sync: running', $scope(ProvisioningForceSyncRequest::query())->where('status', ProvisioningForceSyncRequest::RUNNING)->count()],
        ];

        $this->info('Provisioning ops status'.($op ? " — operator {$op}" : ' — all operators'));
        $this->table(['Queue', 'Count'], $rows);
        $this->line('Drain hints: async → sophix:provisioning:poll-async · mismatches → sophix:provisioning:reconcile · ops actions → sophix:provisioning:ops-fix');

        return self::SUCCESS;
    }
}
