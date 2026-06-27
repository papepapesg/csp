<?php

namespace Modules\WorkOrder\FieldAudit\Providers;

use Illuminate\Support\ServiceProvider;

/** FieldAudit module bootstrap — owns its migrations, routes and workers. Namespace unchanged. */
class FieldAuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
    }
}
