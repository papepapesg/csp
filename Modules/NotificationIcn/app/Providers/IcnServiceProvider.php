<?php

namespace Modules\Notification\Icn\Providers;

use Illuminate\Support\ServiceProvider;

/** Icn module bootstrap — owns its migrations, routes and workers. Namespace unchanged. */
class IcnServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
    }
}
