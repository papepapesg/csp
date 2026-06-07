<?php

namespace Modules\Reporting\Projectors;

use App\Foundation\Events\Outbox\InboxEvent;
use App\Foundation\Events\OutboxEventPublished;
use Illuminate\Support\Facades\DB;
use Modules\Reporting\Models\ReportDailyMetric;

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

        foreach ($this->metricsFor($event->event_type, $payload) as [$key, $delta]) {
            $this->increment($operator, $date, $key, $delta);
        }

        $inbox->update(['processed_at' => now()]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<int, array{0:string,1:float}>
     */
    private function metricsFor(string $type, array $payload): array
    {
        return match ($type) {
            'SubscriptionCreated' => [['subscriptions_created', 1]],
            'SubscriptionActivated' => [['subscriptions_activated', 1]],
            'SubscriptionTerminated' => [['subscriptions_terminated', 1]],
            'InvoiceGenerated' => [['invoices_generated', 1], ['invoices_amount', (float) ($payload['total'] ?? 0)]],
            'PaymentReceived' => [['payments_count', 1], ['payments_amount', (float) ($payload['amount'] ?? 0)]],
            'InvoicePaid' => [['invoices_paid', 1]],
            'TicketCreated' => [['tickets_created', 1]],
            'TicketResolved' => [['tickets_resolved', 1]],
            'WorkOrderFinalized' => [['work_orders_finalized', 1]],
            'OrderCaptured' => [['orders_captured', 1]],
            'OrderCompleted' => [['orders_completed', 1]],
            'WalletToppedUp' => [['wallet_topups_amount', (float) ($payload['amount'] ?? 0)]],
            default => [],
        };
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
