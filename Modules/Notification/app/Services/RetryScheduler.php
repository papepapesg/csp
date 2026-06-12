<?php

namespace Modules\Notification\Services;

use Illuminate\Support\Facades\DB;
use Modules\Notification\Dispatch\DispatchService;
use Modules\Notification\Models\NotificationDeliveryAttempt;

/**
 * NOT-01 retry scanner (retry flow). Picks up delivery attempts in PENDING_RETRY whose
 * next_attempt_at is due — both transient-failure retries (F-2) and time-window deferrals
 * (R-4/P-5) — re-dispatches them, and recomputes the parent notification_log's final
 * status. Runs every minute via sophix:notification:retry-dispatch.
 */
class RetryScheduler
{
    public function __construct(
        private readonly DispatchService $dispatch,
        private readonly NotificationOrchestrator $orchestrator,
    ) {}

    public function run(?string $operator = null, int $limit = 200): int
    {
        $due = NotificationDeliveryAttempt::query()
            ->where('status', NotificationDeliveryAttempt::PENDING_RETRY)
            ->where('next_attempt_at', '<=', now())
            ->when($operator, fn ($q) => $q->where('operator_code', $operator))
            ->orderBy('next_attempt_at')
            ->limit($limit)
            ->get();

        $processed = 0;
        foreach ($due as $attempt) {
            DB::transaction(function () use ($attempt) {
                // Re-check under lock; a concurrent scanner may have taken it.
                $fresh = NotificationDeliveryAttempt::query()->whereKey($attempt->id)->lockForUpdate()->first();
                if (! $fresh || $fresh->status !== NotificationDeliveryAttempt::PENDING_RETRY) {
                    return;
                }
                // Mark the row consumed so it isn't re-picked, then re-dispatch (new attempt row).
                $fresh->update(['status' => NotificationDeliveryAttempt::FAILED, 'next_attempt_at' => null]);
                $this->dispatch->retry($fresh);
                $this->orchestrator->recomputeAndPersist($fresh->notification_id);
            });
            $processed++;
        }

        return $processed;
    }
}
