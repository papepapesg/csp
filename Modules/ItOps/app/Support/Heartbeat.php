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

    /** A worker should stop (to be restarted by supervisor) if a control asks for it. */
    public static function shouldStop(string $service): bool
    {
        try {
            $control = ServiceControl::query()->find($service);
            if ($control && $control->command === 'RESTART' && $control->acknowledged_at === null) {
                $control->update(['acknowledged_at' => now(), 'command' => null]);

                return true;
            }
        } catch (\Throwable) {
            // ignore
        }

        return false;
    }
}
