<?php

namespace Modules\Osr\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Nwidart\Modules\Support\ModuleServiceProvider;

class OsrServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Osr';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'osr';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        \Modules\Osr\Console\ReservationExpiryCommand::class,
        \Modules\Osr\Console\OpsStatusCommand::class,
        \Modules\Osr\Console\StockShowCommand::class,
    ];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
        OsrWorkflowProvider::class,
    ];

    /**
     * Define module schedules.
     *
     * @param  $schedule
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command('sophix:stock:expire-reservations')->everyTenMinutes()->withoutOverlapping();
    }
}
