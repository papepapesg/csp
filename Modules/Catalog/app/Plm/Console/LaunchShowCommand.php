<?php

namespace Modules\Catalog\Plm\Console;

use Illuminate\Console\Command;
use Modules\Catalog\Plm\Models\PackageLaunchCheck;
use Modules\Catalog\Plm\Models\PackageLaunchPlan;

/**
 * Ops review: show one package-launch plan's lifecycle state, schedule and its
 * recorded validation checks (read-only) — so ops can see why a plan is (or
 * isn't) progressing before touching anything.
 */
class LaunchShowCommand extends Command
{
    protected $signature = 'sophix:catalog:launch-show {plan : The launch_plan_id (plp_...)}';

    protected $description = 'Review: show a package-launch plan and its validation checks (read-only)';

    public function handle(): int
    {
        $planId = (string) $this->argument('plan');
        $plan = PackageLaunchPlan::query()->where('launch_plan_id', $planId)->first();
        if (! $plan) {
            $this->warn("No launch plan found for {$planId}.");

            return self::SUCCESS;
        }

        $this->table(['Field', 'Value'], [
            ['launch_plan_id', $plan->launch_plan_id],
            ['operator_code', $plan->operator_code],
            ['package_code', $plan->package_code],
            ['package_id', $plan->package_id],
            ['package_version_id', $plan->package_version_id],
            ['launch_type', $plan->launch_type],
            ['status', $plan->status],
            ['requested_launch_at', (string) $plan->requested_launch_at],
            ['activated_at', (string) $plan->activated_at],
            ['requested_by_user_id', (string) $plan->requested_by_user_id],
            ['approved_by_user_id', (string) $plan->approved_by_user_id],
        ]);

        $checks = $plan->checks()->orderBy('check_status')->get();
        if ($checks->isEmpty()) {
            $this->line('No validation checks recorded yet (plan has not been validated).');
        } else {
            $this->table(['check_code', 'check_status', 'message'], $checks->map(fn ($c) => [
                $c->check_code,
                $c->check_status,
                $c->message,
            ])->all());
        }

        $fails = $checks->where('check_status', PackageLaunchCheck::STATUS_FAIL)->count();
        if ($fails > 0) {
            $this->warn("{$fails} FAIL check(s) — this plan cannot pass activation until they are resolved.");
        }

        return self::SUCCESS;
    }
}
