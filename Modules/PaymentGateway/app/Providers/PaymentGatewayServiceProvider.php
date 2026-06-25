<?php

namespace Modules\PaymentGateway\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\PaymentGateway\Console\CallbackShowCommand;
use Modules\PaymentGateway\Console\OpsStatusCommand;
use Nwidart\Modules\Support\ModuleServiceProvider;

class PaymentGatewayServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'PaymentGateway';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'paymentgateway';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [];

    /**
     * Boot the module, registering its ops console commands (read-only reviews)
     * only when running in the console.
     */
    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                OpsStatusCommand::class,
                CallbackShowCommand::class,
            ]);
        }
    }

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
