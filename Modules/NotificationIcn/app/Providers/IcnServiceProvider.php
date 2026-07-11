<?php

namespace Modules\Notification\Icn\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Notification\Icn\Console\OpsStatusCommand;
use Modules\Notification\Console\Icn\StaffExpireCommand;
use Modules\Notification\Console\Icn\StaffRetryCommand;
use Illuminate\Console\Scheduling\Schedule;

/** Icn module bootstrap — owns its migrations, routes and workers. Namespace unchanged. */
class IcnServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(Schedule $schedule): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([OpsStatusCommand::class, StaffRetryCommand::class, StaffExpireCommand::class]);
            $schedule->command('sophix:icn:retry')->everyFiveMinutes()->withoutOverlapping();
            $schedule->command('sophix:icn:expire')->hourly()->withoutOverlapping();
        }
    }
}
