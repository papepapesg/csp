<?php

namespace Modules\Workforce\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Nwidart\Modules\Support\ModuleServiceProvider;

class WorkforceServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Workforce';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'workforce';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    // protected array $commands = [];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * Define module schedules.
     *
     * @param  $schedule
     */
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }

    /**
     * Boot the module and register its OPS CONSOLE COMMANDS.
     */
    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\Workforce\Console\OpsStatusCommand::class,
                \Modules\Workforce\Console\ContractorShowCommand::class,
                \Modules\Workforce\Console\ReleaseWoCommand::class,
            ]);
        }
    }
}
