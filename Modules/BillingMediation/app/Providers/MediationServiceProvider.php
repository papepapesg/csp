<?php

namespace Modules\Billing\Mediation\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Billing\Mediation\Console\RateUsageCommand;

/** MED-01/RAT-01 mediation module bootstrap — owns its schema, routes, rating worker + cache listener. */
class MediationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([RateUsageCommand::class]);
        }
    }
}
