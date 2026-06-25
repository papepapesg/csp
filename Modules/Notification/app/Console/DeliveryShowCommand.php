<?php

namespace Modules\Notification\Console;

use Illuminate\Console\Command;
use Modules\Notification\Models\NotificationDeliveryAttempt;
use Modules\Notification\Models\NotificationLog;

/**
 * Ops review: show one NOT-01 customer notification's audit row (notification_log) and
 * every per-channel delivery attempt it produced (read-only), so support can see the
 * cross-channel outcome and why individual channels failed before touching anything.
 */
class DeliveryShowCommand extends Command
{
    protected $signature = 'sophix:notification:delivery-show {id : The notification_log id (ntf_...)}';

    protected $description = 'Review: show a customer notification log and its delivery attempts (read-only)';

    public function handle(): int
    {
        $id = (string) $this->argument('id');
        $log = NotificationLog::query()->whereKey($id)->first();
        if (! $log) {
            $this->warn("No notification_log found for id {$id}.");

            return self::SUCCESS;
        }

        $this->table(['Field', 'Value'], [
            ['id', $log->id],
            ['operator_code', $log->operator_code],
            ['customer_id', $log->customer_id ?? '—'],
            ['event_type', $log->event_type],
            ['final_status', $log->final_status],
            ['channels_attempted', implode(', ', (array) ($log->channels_attempted ?? [])) ?: '—'],
            ['dispatched_at', (string) $log->dispatched_at],
            ['source_entity_id', $log->source_entity_id ?? '—'],
            ['manual_resend_by', $log->manual_resend_by ?? '—'],
            ['original_notification_id', $log->original_notification_id ?? '—'],
        ]);

        $attempts = NotificationDeliveryAttempt::query()
            ->where('notification_id', $log->id)
            ->orderBy('attempt_number')
            ->get();

        if ($attempts->isEmpty()) {
            $this->line('No delivery attempts recorded.');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Channel', 'Recipient', 'Status', 'Failure category', 'Next attempt at'],
            $attempts->map(fn (NotificationDeliveryAttempt $a) => [
                $a->attempt_number,
                $a->channel,
                $a->recipient,
                $a->status,
                $a->failure_category ?? '—',
                (string) ($a->next_attempt_at ?? '—'),
            ])->all()
        );

        return self::SUCCESS;
    }
}
