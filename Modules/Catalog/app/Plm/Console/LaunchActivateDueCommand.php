<?php

namespace Modules\Catalog\Plm\Console;

use Illuminate\Console\Command;
use Modules\Catalog\Plm\Models\PackageLaunchPlan;
use Modules\Catalog\Plm\Services\PackageLaunchService;

/**
 * Ops safe-correction: run the SIP-02 §11 scheduled-launch worker for one
 * operator — activate every SCHEDULED launch plan whose requested_launch_at is
 * due (PackageLaunchService::activateDuePlans, the same operation behind the
 * package-launch admin endpoint). Each plan is re-validated immediately before
 * activation, so a plan with a blocking FAIL simply stays SCHEDULED. It mutates
 * state, so it is gated behind --confirm; without it the command is a dry count.
 */
class LaunchActivateDueCommand extends Command
{
    protected $signature = 'sophix:catalog:launch-activate-due
        {operator : The operator_code to drain}
        {--confirm : Required to actually activate (otherwise reports the due count only)}';

    protected $description = 'Safe-correction: activate due SCHEDULED package-launch plans for one operator';

    public function handle(PackageLaunchService $launch): int
    {
        $operator = (string) $this->argument('operator');

        $due = PackageLaunchPlan::query()
            ->where('operator_code', $operator)
            ->where('status', PackageLaunchPlan::STATUS_SCHEDULED)
            ->where(fn ($q) => $q->whereNull('requested_launch_at')->orWhere('requested_launch_at', '<=', now()))
            ->count();

        if (! $this->option('confirm')) {
            $this->warn("{$due} SCHEDULED plan(s) due for operator {$operator}. Re-run with --confirm to activate.");

            return self::SUCCESS;
        }

        $activated = $launch->activateDuePlans($operator);
        $this->info("launch-activate-due: activated {$activated} of {$due} due plan(s) for {$operator} (blocked plans stay SCHEDULED).");

        return self::SUCCESS;
    }
}
