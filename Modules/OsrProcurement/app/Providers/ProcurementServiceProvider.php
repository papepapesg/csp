<?php

namespace Modules\Osr\Procurement\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Osr\Procurement\Console\OpsStatusCommand;

/** Procurement module bootstrap — owns its migrations, routes and workers. Namespace unchanged. */
class ProcurementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([OpsStatusCommand::class]);
        }
    }
}
