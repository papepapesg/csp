<?php

namespace Modules\Billing\Services;

use App\Foundation\Support\Id;
use Illuminate\Support\Facades\DB;

/**
 * BIL-02-GEN-01 rule group Q — generation failure queue. Recoverable failures
 * (customer snapshot fetch, BIL-01 unavailable, tax service timeout) are
 * persisted with the full retry context so a scanner re-attempts with backoff,
 * rather than losing the invoice or blocking the caller. Admin-visible; after
 * the retry budget an entry escalates to GAVE_UP_AUTO for human review.
 */
class GenerationFailureService
{
    private const BACKOFF_MINUTES = [15, 30, 60, 120, 240, 480, 1440, 1440];

    /** @param array<string,mixed> $context */
    public function enqueue(string $operator, string $triggerCode, ?string $subscriptionId, array $context, string $reasonCode, ?string $detail = null): void
    {
        // One open entry per (subscription, trigger) — repeated failures bump retry,
        // they do not pile up.
        $existing = DB::table('generation_failure_queue')
            ->where('operator_code', $operator)->where('trigger_code', $triggerCode)
            ->where('subscription_id', $subscriptionId)->where('status', 'PENDING_RETRY')->first();

        if ($existing) {
            DB::table('generation_failure_queue')->where('failure_id', $existing->failure_id)->update([
                'reason_code' => $reasonCode, 'reason_detail' => $detail,
                'next_retry_at' => $this->nextRetryAt((int) $existing->retry_count), 'updated_at' => now(),
            ]);

            return;
        }

        DB::table('generation_failure_queue')->insert([
            'failure_id' => Id::make('gfq'),
            'operator_code' => $operator,
            'trigger_code' => $triggerCode,
            'subscription_id' => $subscriptionId,
            'context' => json_encode($context),
            'reason_code' => $reasonCode,
            'reason_detail' => $detail,
            'status' => 'PENDING_RETRY',
            'retry_count' => 0,
            'next_retry_at' => $this->nextRetryAt(0),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A successful generation for this subscription clears its open queue entries. */
    public function resolveFor(string $operator, ?string $subscriptionId, string $triggerCode): void
    {
        if (! $subscriptionId) {
            return;
        }
        DB::table('generation_failure_queue')
            ->where('operator_code', $operator)->where('trigger_code', $triggerCode)
            ->where('subscription_id', $subscriptionId)->where('status', 'PENDING_RETRY')
            ->update(['status' => 'RETRIED_SUCCESS', 'updated_at' => now()]);
    }

    private function nextRetryAt(int $retryCount): \Illuminate\Support\Carbon
    {
        $minutes = self::BACKOFF_MINUTES[min($retryCount, count(self::BACKOFF_MINUTES) - 1)];

        return now()->addMinutes($minutes);
    }
}
