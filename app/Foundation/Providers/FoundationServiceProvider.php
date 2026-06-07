<?php

namespace App\Foundation\Providers;

use App\Foundation\Console\DispatchOutboxCommand;
use App\Foundation\Console\GeneratePostmanCommand;
use App\Foundation\Events\Drivers\KafkaEventBus;
use App\Foundation\Events\Drivers\OutboxEventBus;
use App\Foundation\Events\EventBus;
use App\Foundation\Rules\NativeRuleEngine;
use App\Foundation\Rules\RuleEngine;
use App\Foundation\Workflow\OperationManager;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the SOPHIX foundation: event bus, rules engine, workflow manager and
 * console commands. Driver bindings honour config/sophix.php so the Java-stack
 * components (Kafka/Camunda/Drools) can be swapped in without touching modules.
 */
class FoundationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EventBus::class, function () {
            return config('sophix.event_bus') === 'kafka'
                ? new KafkaEventBus
                : new OutboxEventBus;
        });

        $this->app->singleton(RuleEngine::class, NativeRuleEngine::class);
        $this->app->singleton(OperationManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DispatchOutboxCommand::class,
                GeneratePostmanCommand::class,
            ]);
        }
    }
}
