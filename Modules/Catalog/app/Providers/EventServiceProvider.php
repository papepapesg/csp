<?php

namespace Modules\Catalog\Providers;

use App\Foundation\Events\OutboxEventPublished;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Catalog\Listeners\ApplyHomePassTransitionOnApproval;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        // RLM-CFG-01 H-5: a granted EM-CFG-04 approval applies the HomePass status transition.
        OutboxEventPublished::class => [ApplyHomePassTransitionOnApproval::class],
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
