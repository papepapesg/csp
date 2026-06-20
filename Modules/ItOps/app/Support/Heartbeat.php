<?php

namespace Modules\ItOps\Support;

use Modules\ItOps\Models\ServiceControl;
use Modules\ItOps\Models\ServiceHeartbeat;

/**
 * Worker/service liveness + control. Workers call ping() each loop and
 * shouldStop() to honour a RESTART/PAUSE requested from the IT-Ops console.
 */
class Heartbeat
{
    /** @param array<string,mixed> $metrics */
    public static function ping(string $service, ?string $instanceId = null, array $metrics = []): void
    {
        try {
            ServiceHeartbeat::query()->updateOrCreate(
                ['service' => $service],
                ['instance_id' => $instanceId, 'status' => 'UP', 'metrics' => $metrics ?: null, 'last_seen_at' => now()],
            );
        } catch (\Throwable) {
            // heartbeat is best-effort
        }
    }

    /**
     * Whether a worker should stop its loop, honouring an IT-Ops control command:
     *   RESTART — one-shot: stop now; the command is acked + cleared so the
     *             supervisor-restarted worker runs normally again.
     *   PAUSE   — stop and STAY down: the command persists, so a (re)started
     *             worker exits again on every startup until RESUME clears it.
     *   RESUME  — clear a pending PAUSE (consumed) so the worker proceeds.
     */
    public static function shouldStop(string $service): bool
    {
        try {
            $control = ServiceControl::query()->find($service);
            if (! $control) {
                return false;
            }

            if ($control->command === 'RESTART' && $control->acknowledged_at === null) {
                $control->update(['acknowledged_at' => now(), 'command' => null]);

                return true;
            }

            if ($control->command === 'PAUSE') {
                // Record the first acknowledgement, but keep the command so the
                // worker stays paused across restarts until RESUME.
                if ($control->acknowledged_at === null) {
                    $control->update(['acknowledged_at' => now()]);
                }

                return true;
            }

            if ($control->command === 'RESUME') {
                $control->update(['command' => null, 'acknowledged_at' => now()]);

                return false;
            }
        } catch (\Throwable) {
            // ignore
        }

        return false;
    }
}
