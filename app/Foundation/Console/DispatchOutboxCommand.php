<?php

namespace App\Foundation\Console;

use App\Foundation\Events\Drivers\KafkaEventBus;
use App\Foundation\Events\Outbox\OutboxEvent;
use App\Foundation\Events\OutboxEventPublished;
use Illuminate\Console\Command;
use Modules\ItOps\Support\Heartbeat;

/**
 * Forwards committed outbox rows to the active transport (FOUNDATION_KAFKA).
 *
 * native driver -> fire OutboxEventPublished so in-process queued consumers react.
 * kafka  driver -> produce to Kafka via KafkaEventBus::produce().
 *
 * Runs on the scheduler every minute and can also be invoked on demand.
 */
class DispatchOutboxCommand extends Command
{
    protected $signature = 'sophix:outbox:dispatch {--limit=200}';

    protected $description = 'Publish committed transactional-outbox events to the event bus';

    public function handle(): int
    {
        $driver = (string) config('sophix.event_bus', 'outbox');
        $limit = (int) $this->option('limit');

        $rows = OutboxEvent::query()
            ->whereNull('published_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            try {
                if ($driver === 'kafka') {
                    app(KafkaEventBus::class)->produce($row);
                } else {
                    OutboxEventPublished::dispatch($row);
                }

                $row->update(['published_at' => now(), 'attempts' => $row->attempts + 1]);
            } catch (\Throwable $e) {
                $row->increment('attempts');
                $this->error("outbox {$row->event_id} failed: {$e->getMessage()}");
            }
        }

        if (class_exists(Heartbeat::class)) {
            Heartbeat::ping('outbox-dispatcher', null, ['dispatched' => $rows->count()]);
        }

        $this->info("dispatched {$rows->count()} outbox event(s) via [{$driver}]");

        return self::SUCCESS;
    }
}
