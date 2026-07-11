<?php

namespace Modules\Billing\Intent\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Billing\Intent\Console\OpsStatusCommand;

/** Intent module bootstrap — owns its migrations and the BillableEvent catalog routes. */
class IntentServiceProvider extends ServiceProvider
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
    }
}
