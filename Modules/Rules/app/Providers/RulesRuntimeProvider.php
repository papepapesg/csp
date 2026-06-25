<?php

namespace Modules\Rules\Providers;

use App\Foundation\Rules\RuleEngine;
use Illuminate\Support\ServiceProvider;
use Modules\Rules\Engine\DataDrivenRuleEngine;
use Modules\Rules\Engine\DroolsRuleEngine;
use Modules\Rules\Workflow\EvaluateRuleHandler;
use Modules\Workflow\Engine\TaskRegistry;

/**
 * Binds the RuleEngine contract to the configured driver: native decision
 * tables (default) or Drools/KIE Server (FOUNDATION_DROOLS topology). Same
 * contract either way — callers and registered fallbacks are driver-agnostic.
 * Also registers the rules.evaluate toolbox step.
 */
class RulesRuntimeProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RuleEngine::class, match (config('sophix.rules_driver', 'native')) {
            'drools' => DroolsRuleEngine::class,
            default => DataDrivenRuleEngine::class,
        });
    }

    public function boot(): void
    {
        $this->app->make(TaskRegistry::class)->register(EvaluateRuleHandler::class);

        // Any same-process write to a decision table (studio API, seeder, console)
        // drops the engine's resolution memo, so policy edits apply immediately
        // here; other long-running processes converge within the memo TTL.
        $forget = function (): void {
            $engine = $this->app->make(RuleEngine::class);
            if ($engine instanceof DataDrivenRuleEngine) {
                $engine->forgetMemo();
            }
        };
        \Modules\Rules\Models\DecisionTable::saved($forget);
        \Modules\Rules\Models\DecisionTable::deleted($forget);

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\Rules\Console\OpsRuleStatusCommand::class,
                \Modules\Rules\Console\RuleTableShowCommand::class,
                \Modules\Rules\Console\RuleEvaluateCommand::class,
            ]);
        }
    }
}
