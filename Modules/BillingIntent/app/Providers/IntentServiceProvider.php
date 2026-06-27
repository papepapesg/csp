<?php

namespace Modules\Billing\Intent\Providers;

use Illuminate\Support\ServiceProvider;

/** Intent module bootstrap — owns its migrations, routes and workers. Namespace unchanged. */
class IntentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
    }
}
