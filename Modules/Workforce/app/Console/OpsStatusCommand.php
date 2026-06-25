<?php

namespace Modules\Workforce\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Workforce\Models\Contractor;
use Modules\Workforce\Models\ContractorSlotCommitment;

/**
 * Ops review: a one-glance health summary of the EM-02 workforce/capacity tables an
 * operator needs to watch (read-only) — non-active contractors still holding ACTIVE
 * slot commitments, commitments whose committed day has passed but are still ACTIVE
 * (stale, capacity-blocking), and overall commitment-ledger counts by status.
 */
class OpsStatusCommand extends Command
{
    protected $signature = 'sophix:workforce:ops-status {--operator= : Scope to one operator code (default: all)}';

    protected $description = 'Review: counts of workforce items needing ops attention (read-only)';

    public function handle(): int
    {
        $op = $this->option('operator');
        $scope = fn ($q) => $op ? $q->where('operator_code', $op) : $q;

        // Non-active contractors (SUSPENDED/RETIRED) that still own ACTIVE slot commitments.
        $inactiveIds = $scope(Contractor::query())
            ->where('status', '!=', 'ACTIVE')->pluck('contractor_id');
        $orphanCommitments = $scope(ContractorSlotCommitment::query())
            ->where('status', ContractorSlotCommitment::ACTIVE)
            ->whereIn('contractor_id', $inactiveIds)->count();

        // Stale: still ACTIVE but the day it was committed for is in the past.
        $stale = $scope(ContractorSlotCommitment::query())
            ->where('status', ContractorSlotCommitment::ACTIVE)
            ->whereDate('committed_for_datetime', '<', now()->toDateString())->count();

        $rows = [
            ['Contractors (SUSPENDED/RETIRED)', $scope(Contractor::query())->where('status', '!=', 'ACTIVE')->count()],
            ['ACTIVE commitments held by non-active contractors', $orphanCommitments],
            ['Stale ACTIVE commitments (committed day in the past)', $stale],
            ['Commitments: ACTIVE', $scope(ContractorSlotCommitment::query())->where('status', ContractorSlotCommitment::ACTIVE)->count()],
            ['Commitments: CONSUMED', $scope(ContractorSlotCommitment::query())->where('status', ContractorSlotCommitment::CONSUMED)->count()],
            ['Commitments: RELEASED', $scope(ContractorSlotCommitment::query())->where('status', ContractorSlotCommitment::RELEASED)->count()],
            ['Commitments: EXPIRED', $scope(ContractorSlotCommitment::query())->where('status', ContractorSlotCommitment::EXPIRED)->count()],
            ['Availability slots: inactive', $scope(DB::table('contractor_availability_slot'))->where('active', false)->count()],
        ];

        $this->info('Workforce ops status'.($op ? " — operator {$op}" : ' — all operators'));
        $this->table(['Queue', 'Count'], $rows);
        $this->line('Inspect: sophix:workforce:contractor-show {contractor} · release stale WO commitments: sophix:workforce:release-wo {wo} --confirm');

        return self::SUCCESS;
    }
}
