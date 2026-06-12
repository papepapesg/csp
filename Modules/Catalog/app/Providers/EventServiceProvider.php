<?php

namespace Modules\Catalog\Providers;

use App\Foundation\Events\OutboxEventPublished;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Catalog\Listeners\ApplyHomePassTransitionOnApproval;
use Modules\Catalog\Listeners\ApplyPackageLaunchApproval;
use Modules\Catalog\Listeners\CatalogCacheInvalidator;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        OutboxEventPublished::class => [
            // RLM-CFG-01 H-5: a granted EM-CFG-04 approval applies the HomePass status transition.
            ApplyHomePassTransitionOnApproval::class,
            // SIP-02: a granted/rejected launch-plan approval advances the launch plan.
            ApplyPackageLaunchApproval::class,
            // FOUNDATION_CACHE: catalog lifecycle changes evict stale read-model snapshots.
            CatalogCacheInvalidator::class,
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
