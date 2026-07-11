<?php

namespace Modules\Catalog\Rating\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Catalog\Rating\Console\OpsStatusCommand;

/** Rating module bootstrap — owns its migrations, routes and workers. Namespace unchanged. */
class RatingServiceProvider extends ServiceProvider
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
