<?php

namespace Modules\Notification\Services;

use App\Foundation\Events\EventBus;
use Modules\Notification\Models\RenderFailureQueue;

/**
 * NOT-01 render-failure auto-retry (R-NOT-01-D-7). Walks render_failure_queue rows whose
 * next_attempt_at is due, re-attempts the render by replaying the original event through
 * the orchestrator, and on persistent failure bumps the attempt count with exponential
 * backoff. After the retry budget (default 8) the row moves to GAVE_UP_AUTO for admin
 * recovery (O-5). Runs via sophix:notification:retry-render.
 */
class RenderRetryService
{
    public function __construct(
        private readonly NotificationOrchestrator $orchestrator,
        private readonly EventBus $events,
    ) {}

    private function backoff(): array
    {
        return config('sophix.notification.render_backoff_seconds', [300, 900, 1800, 3600, 7200, 14400, 28800, 86400]);
    }

    public function run(?string $operator = null, int $limit = 100): int
    {
        $due = RenderFailureQueue::query()
            ->where('status', RenderFailureQueue::PENDING_RETRY)
            ->where('next_attempt_at', '<=', now())
            ->when($operator, fn ($q) => $q->where('operator_code', $operator))
            ->limit($limit)
            ->get();

        $processed = 0;
        foreach ($due as $rfq) {
            $this->attempt($rfq);
            $processed++;
        }

        return $processed;
    }

    /** Re-attempt one queued render; resolve on success, back off or give up on repeat failure. */
    public function attempt(RenderFailureQueue $rfq): RenderFailureQueue
    {
        $before = $rfq->attempt_count;
        $log = $this->orchestrator->ingest($rfq->event_type, $rfq->operator_code, $rfq->original_event_payload, [
            'sourceEntityId' => $rfq->source_entity_id,
        ]);

        // If the replay produced no fresh failure row for this entity, the render succeeded.
        $stillFailing = RenderFailureQueue::query()
            ->where('source_entity_id', $rfq->source_entity_id)
            ->where('status', RenderFailureQueue::PENDING_RETRY)
            ->where('id', '!=', $rfq->id)
            ->where('created_at', '>=', $rfq->updated_at)
            ->exists();

        if (! $stillFailing) {
            $rfq->update(['status' => RenderFailureQueue::RESOLVED, 'resolved_at' => now(), 'resolved_by' => 'auto']);

            return $rfq->refresh();
        }

        $next = $before; // 1-based attempt index
        if ($next >= count($this->backoff())) {
            $rfq->update(['status' => RenderFailureQueue::GAVE_UP_AUTO, 'attempt_count' => $before + 1, 'last_attempt_at' => now(), 'next_attempt_at' => null]);
        } else {
            $rfq->update([
                'attempt_count' => $before + 1,
                'last_attempt_at' => now(),
                'next_attempt_at' => now()->addSeconds((int) ($this->backoff()[$next] ?? 86400)),
            ]);
        }

        return $rfq->refresh();
    }
}
