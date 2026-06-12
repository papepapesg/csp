<?php

namespace Modules\Notification\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Nwidart\Modules\Support\ModuleServiceProvider;

class NotificationServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Notification';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'notification';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        \Modules\Notification\Console\RetryDispatchCommand::class,
        \Modules\Notification\Console\RetryRenderCommand::class,
        \Modules\Notification\Console\Icn\StaffRetryCommand::class,
        \Modules\Notification\Console\Icn\StaffExpireCommand::class,
    ];

    public function register(): void
    {
        parent::register();
        // Adapter/engine registries cache initialized adapters; keep them singletons so the
        // cache lives for the request/worker lifetime.
        $this->app->singleton(\Modules\Notification\Dispatch\ChannelAdapterRegistry::class);
        $this->app->singleton(\Modules\Notification\Rendering\TemplateEngineRegistry::class);
        $this->app->singleton(\Modules\Notification\Icn\StaffAdapterRegistry::class);
    }

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
        NotificationWorkflowProvider::class,
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
