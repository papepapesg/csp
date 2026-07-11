<?php

namespace Modules\Billing\Adjustments\Providers;

use App\Foundation\Approvals\ApprovalDefinition;
use App\Foundation\Rules\RuleEngine;
use Illuminate\Support\ServiceProvider;
use Modules\Billing\Adjustments\Services\AdjustmentService;
use Modules\Billing\Adjustments\Console\OpsStatusCommand;

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

        if ($this->app->runningInConsole()) {
            $this->commands([OpsStatusCommand::class]);
        }

        // ADJ-01 approval routing fallback: when no decision table is deployed for
        // rules.billing.adjustment-approval, select the process from the base ADJUSTMENT
        // process config (auto-approve under its threshold, else single approval).
        $this->app->make(RuleEngine::class)->register(AdjustmentService::APPROVAL_RULE_SET, function (array $facts) {
            $config = ApprovalDefinition::query()
                ->where('operator_code', $facts['operatorCode'] ?? '')->where('entity_type', 'ADJUSTMENT')->whereNull('action')
                ->first()?->config ?? [];
            $autoUnder = $config['auto_approve_under'] ?? null;
            if ($autoUnder !== null && (float) ($facts['amount'] ?? 0) < (float) $autoUnder) {
                return ['stepsRequired' => 0, 'approvalProcess' => 'AUTO', 'ruleId' => 'FALLBACK-ADJ-PROCESS-CONFIG'];
            }

            return ['stepsRequired' => 1, 'approvalProcess' => 'SINGLE', 'ruleId' => 'FALLBACK-ADJ-PROCESS-CONFIG'];
        });
    }
}
