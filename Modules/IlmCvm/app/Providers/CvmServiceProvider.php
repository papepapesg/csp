<?php

namespace Modules\Ilm\Cvm\Providers;

use Illuminate\Support\ServiceProvider;

/** Cvm module bootstrap — owns its migrations, routes and workers. Namespace unchanged. */
class CvmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
    }
}
