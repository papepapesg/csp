<?php

namespace Modules\Billing\Mediation\Providers;

use App\Foundation\Events\OutboxEventPublished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Billing\Mediation\Console\RateUsageCommand;
use Modules\Billing\Mediation\Listeners\EvictPlmCatalogCache;

/** MED-01/RAT-01 mediation module bootstrap — owns its schema, routes, rating worker + cache listener. */
class MediationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');

        // FOUNDATION_CACHE: evict cached PLM catalog copies on relevant events.
        Event::listen(OutboxEventPublished::class, [EvictPlmCatalogCache::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([RateUsageCommand::class]);
        }
    }
}
