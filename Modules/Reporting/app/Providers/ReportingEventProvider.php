<?php

namespace Modules\Reporting\Providers;

use App\Foundation\Events\OutboxEventPublished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Reporting\Projectors\ReportMetricProjector;

/**
 * Wires the REP-01 read-model projector to committed outbox events.
 */
class ReportingEventProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(OutboxEventPublished::class, [ReportMetricProjector::class, 'handle']);
    }
}
