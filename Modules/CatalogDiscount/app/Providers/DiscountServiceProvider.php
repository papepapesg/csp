<?php

namespace Modules\Catalog\Discount\Providers;

use Illuminate\Support\ServiceProvider;

/** Discount module bootstrap — owns its migrations, routes and workers. Namespace unchanged. */
class DiscountServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
    }
}
