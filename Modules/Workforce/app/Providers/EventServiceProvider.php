<?php

namespace Modules\Workforce\Providers;

use App\Foundation\Events\OutboxEventPublished;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Workforce\Listeners\ResolveSlotCommitmentOnWoLifecycle;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        // WO finalized/cancelled -> consume/release the contractor slot commitment.
        OutboxEventPublished::class => [ResolveSlotCommitmentOnWoLifecycle::class],
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
