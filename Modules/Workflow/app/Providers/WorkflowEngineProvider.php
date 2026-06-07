<?php

namespace Modules\Workflow\Providers;

use Illuminate\Support\ServiceProvider;
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
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                WorkflowWorkerCommand::class,
                WorkflowTickCommand::class,
            ]);
        }
    }
}
