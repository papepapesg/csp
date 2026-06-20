<?php

namespace Modules\Provisioning\Providers;

use App\Foundation\Events\OutboxEventPublished;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Provisioning\Listeners\SyncProvisioningOnAccountStatusChanged;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        OutboxEventPublished::class => [
            // ILM-CFG-01 R-ILM-S-3: a provisioning-affecting account status change reaches the network.
            SyncProvisioningOnAccountStatusChanged::class,
        ],
    ];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = true;

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}
