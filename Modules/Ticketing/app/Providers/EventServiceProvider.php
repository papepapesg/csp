<?php

namespace Modules\Ticketing\Providers;

use App\Foundation\Events\OutboxEventPublished;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Ticketing\Listeners\ResolveTicketOnWorkOrderFinalized;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        // TCK-01 §9.2: a finalized WO resolves the ticket that was waiting on it.
        OutboxEventPublished::class => [ResolveTicketOnWorkOrderFinalized::class],
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
