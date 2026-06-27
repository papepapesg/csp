<?php

namespace Modules\Osr\Swap\Providers;

use Illuminate\Support\ServiceProvider;

/** Swap module bootstrap — owns its migrations, routes and workers. Namespace unchanged. */
class SwapServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
    }
}
