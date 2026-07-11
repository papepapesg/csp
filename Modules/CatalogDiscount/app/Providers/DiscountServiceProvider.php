<?php

namespace Modules\Catalog\Discount\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Catalog\Discount\Console\OpsStatusCommand;

/** Discount module bootstrap — owns its migrations, routes and workers. Namespace unchanged. */
class DiscountServiceProvider extends ServiceProvider
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
