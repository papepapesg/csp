<?php

namespace Modules\Notification\Providers;

use App\Foundation\Events\OutboxEventPublished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Modules\Notification\Console\DeliveryShowCommand;
use Modules\Notification\Console\Icn\StaffExpireCommand;
use Modules\Notification\Console\Icn\StaffRetryCommand;
use Modules\Notification\Console\OpsStatusCommand;
use Modules\Notification\Console\RetryDispatchCommand;
use Modules\Notification\Console\RetryFixCommand;
use Modules\Notification\Console\RetryRenderCommand;
use Modules\Notification\Dispatch\ChannelAdapterRegistry;
use Modules\Notification\Icn\StaffAdapterRegistry;
use Modules\Notification\Listeners\AccountStatusNotificationBridge;
use Modules\Notification\Listeners\DunningNotificationBridge;
use Modules\Notification\Listeners\NotifyApproversOnApprovalRequested;
use Modules\Notification\Rendering\TemplateEngineRegistry;
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
        RetryDispatchCommand::class,
        RetryRenderCommand::class,
        StaffRetryCommand::class,
        StaffExpireCommand::class,
        OpsStatusCommand::class,
        DeliveryShowCommand::class,
        RetryFixCommand::class,
    ];

    public function boot(): void
    {
        parent::boot();
        // NOT-01 consumes BIL-04 dunning level transitions and routes a customer notice per the
        // operator's routing rules + the customer's channel preferences (channels are config).
        Event::listen(
            OutboxEventPublished::class,
            [DunningNotificationBridge::class, 'handle'],
        );
        // ILM-CFG-01: a customer-visible account status change notifies the customer.
        Event::listen(
            OutboxEventPublished::class,
            [AccountStatusNotificationBridge::class, 'handle'],
        );
        // EM-CFG-04: a pending approval notifies its approver group via ICN-01.
        Event::listen(
            OutboxEventPublished::class,
            [NotifyApproversOnApprovalRequested::class, 'handle'],
        );
    }

    public function register(): void
    {
        parent::register();
        // Adapter/engine registries cache initialized adapters; keep them singletons so the
        // cache lives for the request/worker lifetime.
        $this->app->singleton(ChannelAdapterRegistry::class);
        $this->app->singleton(TemplateEngineRegistry::class);
        $this->app->singleton(StaffAdapterRegistry::class);
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
