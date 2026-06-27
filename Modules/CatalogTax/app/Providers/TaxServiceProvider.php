<?php

namespace Modules\Catalog\Tax\Providers;

use Illuminate\Support\ServiceProvider;

/** Tax module bootstrap — owns its migrations, routes and workers. Namespace unchanged. */
class TaxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
    }
}
