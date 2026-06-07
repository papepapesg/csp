<?php

namespace Modules\Rules\Providers;

use App\Foundation\Rules\RuleEngine;
use Illuminate\Support\ServiceProvider;
use Modules\Rules\Engine\DataDrivenRuleEngine;
use Modules\Rules\Workflow\EvaluateRuleHandler;
use Modules\Workflow\Engine\TaskRegistry;

/**
 * Makes the rule engine data-driven (decision tables) and registers the
 * rules.evaluate toolbox step. Rebinds the foundation RuleEngine contract so
 * policy is configuration, not code.
 */
class RulesRuntimeProvider extends ServiceProvider
{
    public function register(): void
    {
        if (config('sophix.rules_driver', 'native') !== 'drools') {
            $this->app->singleton(RuleEngine::class, DataDrivenRuleEngine::class);
        }
    }

    public function boot(): void
    {
        $this->app->make(TaskRegistry::class)->register(EvaluateRuleHandler::class);
    }
}
