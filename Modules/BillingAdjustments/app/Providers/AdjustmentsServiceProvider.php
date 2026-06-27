<?php

namespace Modules\Billing\Adjustments\Providers;

use App\Foundation\Rules\RuleEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Modules\Billing\Adjustments\Services\AdjustmentService;

/** BIL-02-ADJ-01 adjustments module bootstrap — owns its schema, routes and approval-routing fallback. */
class AdjustmentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');

        // ADJ-01 approval routing fallback: when no decision table is deployed for
        // rules.billing.adjustment-approval, derive {stepsRequired} from adjustment_limits_config.
        $this->app->make(RuleEngine::class)->register(AdjustmentService::APPROVAL_RULE_SET, function (array $facts) {
            $config = DB::table('adjustment_limits_config')->where('operator_code', $facts['operatorCode'] ?? '')->first();
            $steps = (int) ($config->approval_steps_required ?? 1);
            if ($config?->auto_approve_under !== null && (float) ($facts['amount'] ?? 0) < (float) $config->auto_approve_under) {
                $steps = 0;
            }

            return ['stepsRequired' => $steps, 'ruleId' => 'FALLBACK-ADJ-LIMITS-CONFIG'];
        });
    }
}
