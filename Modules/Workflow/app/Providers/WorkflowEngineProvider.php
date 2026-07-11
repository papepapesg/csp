<?php

namespace Modules\Workflow\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use App\Foundation\Workflow\WorkflowRuntime;
use Modules\Workflow\Console\InstanceShowCommand;
use Modules\Workflow\Console\OpsStatusCommand;
use Modules\Workflow\Console\WorkflowTickCommand;
use Modules\Workflow\Console\WorkflowWorkerCommand;
use Modules\Workflow\Engine\TaskRegistry;
use Modules\Workflow\Engine\WorkflowEngine;

/**
 * Binds the workflow engine + step toolbox (TaskRegistry) as singletons so
 * module providers can register their handlers, and wires the worker/tick
 * console commands.
 */
class WorkflowEngineProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TaskRegistry::class);
        $this->app->singleton(WorkflowEngine::class);
        $this->app->singleton(WorkflowRuntime::class, function ($app) {
            return match ((string) config('sophix.workflow_driver', 'native')) {
                'native' => $app->make(WorkflowEngine::class),
                default => throw new \InvalidArgumentException('Unsupported workflow driver ['.config('sophix.workflow_driver').']. Install and bind an adapter before enabling it.'),
            };
        });
    }

    public function boot(Schedule $schedule): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                WorkflowWorkerCommand::class,
                WorkflowTickCommand::class,
                OpsStatusCommand::class,
                InstanceShowCommand::class,
            ]);
            $schedule->command('sophix:workflow:tick')->everyMinute()->withoutOverlapping();
        }
    }
}
