<?php

namespace Modules\Ilm\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Nwidart\Modules\Support\ModuleServiceProvider;

class IlmServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Ilm';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'ilm';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        \Modules\Ilm\Cvm\Console\CvmFlagEvaluatorCommand::class,
        \Modules\Ilm\Console\CustomerShowCommand::class,
        \Modules\Ilm\Console\KycQueueCommand::class,
        \Modules\Ilm\Console\FlagClearCommand::class,
    ];

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
}
