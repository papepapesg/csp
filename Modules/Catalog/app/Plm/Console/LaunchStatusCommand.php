<?php

namespace Modules\Catalog\Plm\Console;

use Illuminate\Console\Command;
use Modules\Catalog\Plm\Models\PackageLaunchCheck;
use Modules\Catalog\Plm\Models\PackageLaunchPlan;

/**
 * Ops review: a one-glance summary of the SIP-02 package-launch pipeline an
 * operator needs to watch (read-only) — how many plans sit in each lifecycle
 * state, and which SCHEDULED plans are already due for the §11 activation worker.
 */
class LaunchStatusCommand extends Command
{
    protected $signature = 'sophix:catalog:launch-status {--operator= : Scope to one operator_code (default: all)}';

    protected $description = 'Review: counts of package-launch plans by lifecycle state, plus due/blocked queues (read-only)';

    public function handle(): int
    {
        $op = $this->option('operator');
        $scope = fn ($q) => $op ? $q->where('operator_code', $op) : $q;

        $byStatus = [
            PackageLaunchPlan::STATUS_DRAFT,
            PackageLaunchPlan::STATUS_READY_FOR_REVIEW,
            PackageLaunchPlan::STATUS_PENDING_APPROVAL,
            PackageLaunchPlan::STATUS_APPROVED,
            PackageLaunchPlan::STATUS_SCHEDULED,
            PackageLaunchPlan::STATUS_ACTIVE,
            PackageLaunchPlan::STATUS_SUSPENDED,
            PackageLaunchPlan::STATUS_END_OF_SALE,
            PackageLaunchPlan::STATUS_END_OF_LIFE,
            PackageLaunchPlan::STATUS_RETIRED,
            PackageLaunchPlan::STATUS_REJECTED,
        ];

        $rows = [];
        foreach ($byStatus as $status) {
            $rows[] = [$status, $scope(PackageLaunchPlan::query())->where('status', $status)->count()];
        }

        $this->info('Catalog package-launch status'.($op ? " — operator {$op}" : ' — all operators'));
        $this->table(['Lifecycle state', 'Plans'], $rows);

        // The queue the §11 worker drains: SCHEDULED plans whose launch time has arrived.
        $dueNow = $scope(PackageLaunchPlan::query())
            ->where('status', PackageLaunchPlan::STATUS_SCHEDULED)
            ->where(fn ($q) => $q->whereNull('requested_launch_at')->orWhere('requested_launch_at', '<=', now()))
            ->count();

        // Plans carrying a blocking validation FAIL — these will not pass activation.
        $blocked = $scope(PackageLaunchPlan::query())
            ->whereIn('launch_plan_id', PackageLaunchCheck::query()
                ->where('check_status', PackageLaunchCheck::STATUS_FAIL)
                ->select('launch_plan_id'))
            ->count();

        $this->table(['Queue', 'Count'], [
            ['SCHEDULED plans due for activation', $dueNow],
            ['Plans with a blocking FAIL check', $blocked],
        ]);

        if ($dueNow > 0) {
            $this->line('Drain hint: activate due plans with sophix:catalog:launch-activate-due {operator} --confirm');
        }

        return self::SUCCESS;
    }
}
