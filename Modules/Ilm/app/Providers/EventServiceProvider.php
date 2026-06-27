<?php

namespace Modules\Ilm\Providers;

use App\Foundation\Events\OutboxEventPublished;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Ilm\Listeners\ApplySubStatusOnApproval;
use Modules\Ilm\Cvm\Listeners\ResumeCvmOfferOnApproval;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        OutboxEventPublished::class => [
            // EM-CFG-04: a granted/rejected CVM offer approval resumes the parked offer.
            ResumeCvmOfferOnApproval::class,
            // EM-CFG-04: a granted sub-status approval applies the held account transition.
            ApplySubStatusOnApproval::class,
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
