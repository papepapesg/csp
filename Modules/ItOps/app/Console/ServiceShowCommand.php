<?php

namespace Modules\ItOps\Console;

use Illuminate\Console\Command;
use Modules\ItOps\Models\ServiceControl;
use Modules\ItOps\Models\ServiceHeartbeat;

/**
 * Ops review: show one worker's live state (read-only) — its last heartbeat,
 * reported metrics, liveness against the 120s threshold, and any pending
 * RESTART/PAUSE/RESUME control command (with its ack), so ops can see why a
 * worker is up, down or held before touching anything.
 */
class ServiceShowCommand extends Command
{
    protected $signature = 'sophix:itops:service-show {service : The service name (e.g. workflow-worker)}';

    protected $description = 'Review: show a service\'s heartbeat and control state (read-only)';

    public function handle(): int
    {
        $service = (string) $this->argument('service');
        $hb = ServiceHeartbeat::query()->find($service);
        $control = ServiceControl::query()->find($service);

        if (! $hb && ! $control) {
            $this->warn("No heartbeat or control record for service '{$service}'.");

            return self::SUCCESS;
        }

        $threshold = now()->subSeconds(120);
        $up = $hb && $hb->last_seen_at && $hb->last_seen_at->gt($threshold);

        $this->table(['Field', 'Value'], [
            ['service', $service],
            ['liveness', $up ? 'UP' : 'DOWN'],
            ['status (reported)', $hb->status ?? '—'],
            ['instance_id', $hb->instance_id ?? '—'],
            ['last_seen_at', $hb && $hb->last_seen_at ? (string) $hb->last_seen_at : '—'],
            ['metrics', $hb && $hb->metrics ? json_encode($hb->metrics) : '—'],
            ['pending command', $control->command ?? '—'],
            ['requested_by', $control->requested_by ?? '—'],
            ['requested_at', $control && $control->requested_at ? (string) $control->requested_at : '—'],
            ['acknowledged_at', $control && $control->acknowledged_at ? (string) $control->acknowledged_at : '—'],
        ]);

        if ($control && $control->command === 'PAUSE') {
            $this->warn("Service is held by a PAUSE command — it will exit on every (re)start until RESUME.");
        }

        return self::SUCCESS;
    }
}
