<?php

namespace Modules\Billing\Services;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Support\Carbon;
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

    /** R-GEN-01-Q-3: retry budget (~8 attempts spanning ~3 days) before GAVE_UP_AUTO. */
    private const MAX_RETRIES = 8;

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

    /**
     * R-GEN-01-Q-2/Q-3 scanner: re-attempt due PENDING_RETRY entries via $dispatch
     * (the owning generator for the trigger_code, which throws on failure). On success
     * the entry clears to RETRIED_SUCCESS; on failure retry_count bumps with exponential
     * backoff, and once the retry budget is spent it escalates to GAVE_UP_AUTO (next_retry
     * cleared) for human review. Operator context is set per entry so the generator runs
     * under the right tenant.
     *
     * @param  callable(string, array<string,mixed>, ?string): void  $dispatch
     * @return array{retried:int, recovered:int, gaveUp:int}
     */
    public function retryDue(?string $operator, callable $dispatch): array
    {
        $rows = DB::table('generation_failure_queue')
            ->where('status', 'PENDING_RETRY')
            ->where('retry_count', '<', self::MAX_RETRIES)
            ->where('next_retry_at', '<=', now())
            ->when($operator, fn ($q) => $q->where('operator_code', $operator))
            ->orderBy('next_retry_at')
            ->limit(500)
            ->get();

        $recovered = $gaveUp = 0;
        foreach ($rows as $row) {
            $context = json_decode($row->context ?? '{}', true) ?: [];
            Context::setOperatorCode($row->operator_code);
            try {
                $dispatch($row->trigger_code, $context, $row->subscription_id);
                // The generator may already have cleared the entry via resolveFor(); force the
                // terminal state for entries still open (e.g. a no-op "nothing due" recovery).
                DB::table('generation_failure_queue')->where('failure_id', $row->failure_id)
                    ->where('status', 'PENDING_RETRY')
                    ->update(['status' => 'RETRIED_SUCCESS', 'retry_count' => $row->retry_count + 1, 'updated_at' => now()]);
                $recovered++;
            } catch (\Throwable $e) {
                $newCount = (int) $row->retry_count + 1;
                $exhausted = $newCount >= self::MAX_RETRIES;
                DB::table('generation_failure_queue')->where('failure_id', $row->failure_id)->update([
                    'status' => $exhausted ? 'GAVE_UP_AUTO' : 'PENDING_RETRY',
                    'retry_count' => $newCount,
                    'reason_detail' => $e->getMessage(),
                    'next_retry_at' => $exhausted ? null : $this->nextRetryAt($newCount),
                    'updated_at' => now(),
                ]);
                if ($exhausted) {
                    $gaveUp++;
                }
            }
        }

        return ['retried' => $rows->count(), 'recovered' => $recovered, 'gaveUp' => $gaveUp];
    }

    private function nextRetryAt(int $retryCount): Carbon
    {
        $minutes = self::BACKOFF_MINUTES[min($retryCount, count(self::BACKOFF_MINUTES) - 1)];

        return now()->addMinutes($minutes);
    }
}
