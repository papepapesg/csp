<?php

namespace Modules\Reporting\Projectors;

use App\Foundation\Events\Outbox\InboxEvent;
use App\Foundation\Events\OutboxEventPublished;
use Illuminate\Support\Facades\DB;
use Modules\Reporting\Models\ReportDailyMetric;
use Modules\Reporting\Support\MetricMap;

/**
 * REP-01 read-model projector. Consumes committed domain events (via the outbox
 * dispatcher) and maintains the reporting mart. Idempotent through the inbox so
 * replays/retries never double-count (HLD §6.6).
 */
class ReportMetricProjector
{
    private const CONSUMER = 'reporting.metrics';

    public function handle(OutboxEventPublished $published): void
    {
        $event = $published->event;

        // Inbox dedupe — process each event at most once for this consumer.
        $inbox = InboxEvent::query()->firstOrCreate(
            ['event_id' => $event->event_id, 'consumer' => self::CONSUMER],
            ['event_type' => $event->event_type],
        );
        if ($inbox->processed_at !== null) {
            return;
        }

        $operator = $event->operator_code ?? 'WIK';
        $date = ($event->created_at ?? now())->toDateString();
        $payload = $event->payload ?? [];

        foreach (MetricMap::for($event->event_type, $payload) as [$key, $delta]) {
            $this->increment($operator, $date, $key, $delta);
        }

        $inbox->update(['processed_at' => now()]);
    }

    private function increment(string $operator, string $date, string $key, float $delta): void
    {
        DB::transaction(function () use ($operator, $date, $key, $delta) {
            $metric = ReportDailyMetric::query()->lockForUpdate()->firstOrNew([
                'operator_code' => $operator,
                'metric_date' => $date,
                'metric_key' => $key,
            ]);
            $metric->value = (float) ($metric->value ?? 0) + $delta;
            $metric->save();
        });
    }
}
