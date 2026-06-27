<?php

namespace Modules\Catalog\Network\Providers;

use Illuminate\Support\ServiceProvider;

/** Network module bootstrap — owns its migrations, routes and workers. Namespace unchanged. */
class NetworkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
    }
}
