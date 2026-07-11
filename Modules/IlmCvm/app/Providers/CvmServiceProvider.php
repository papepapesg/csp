<?php

namespace Modules\Ilm\Cvm\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Ilm\Cvm\Console\CvmFlagEvaluatorCommand;

/** Cvm module bootstrap — owns its migrations, routes and workers. Namespace unchanged. */
class CvmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(Schedule $schedule): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([CvmFlagEvaluatorCommand::class]);
            $schedule->command('sophix:cvm:evaluate-flags')->daily()->withoutOverlapping();
        }
    }
}
