<?php

namespace Modules\Subscription\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Illuminate\Support\Facades\DB;
use Modules\Subscription\Events\SubscriptionEvents;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Models\SubscriptionPauseHistory;

/**
 * SUB-LM-01 — the only writer of the subscription master row. SUB-WF workflows
 * call these methods rather than mutating the row directly (HLD §6.2).
 */
class SubscriptionService
{
    public function __construct(private readonly EventBus $events) {}

    /** @param array<string,mixed> $data */
    public function create(array $data): Subscription
    {
        return DB::transaction(function () use ($data) {
            $data['status_code'] ??= Subscription::PENDING_ACTIVATION;
            $data['last_status_changed_at'] = now();
            $subscription = Subscription::query()->create($data);

            $this->emit(SubscriptionEvents::CREATED, $subscription, [
                'subscriptionId' => $subscription->subscription_id,
                'accountId' => $subscription->account_id,
                'packageRef' => $subscription->package_ref,
                'status' => $subscription->status_code,
            ]);

            return $subscription;
        });
    }

    /**
     * SUB-WF-PAUSE-01 §7.2: open a pause/suspension-history row at commit (one open
     * row per subscription, R-PAUSE-S-3). Idempotent — reuses the open row if present.
     *
     * @param  array<string,mixed>  $data
     */
    public function openPausePeriod(Subscription $subscription, array $data): SubscriptionPauseHistory
    {
        $open = SubscriptionPauseHistory::open($subscription->subscription_id);
        if ($open) {
            return $open;
        }

        return SubscriptionPauseHistory::query()->create(array_merge([
            'operator_code' => $subscription->operator_code,
            'subscription_id' => $subscription->subscription_id,
            'customer_id' => $subscription->customer_id,
            'suspended_at' => now(),
        ], $data));
    }

    /**
     * SUB-WF-RESUME-01: close the open pause/suspension-history row on resume.
     *
     * @param  array<string,mixed>  $data
     */
    public function closePausePeriod(Subscription $subscription, array $data): void
    {
        SubscriptionPauseHistory::open($subscription->subscription_id)
            ?->update(array_merge(['actual_resume_at' => now()], $data));
    }

    /**
     * SUB-LM-01 sub-lm-put-pending-status: move the master into a transient
     * PENDING_* state during the commit window. No lifecycle event is emitted —
     * downstream consumers see only the final commit (the rest state).
     */
    public function setPendingStatus(Subscription $subscription, string $pendingStatus, ?string $transitionType, ?string $reasonCode): Subscription
    {
        $subscription->update([
            'status_code' => $pendingStatus,
            'current_transition_type' => $transitionType,
            'current_transition_reason_code' => $reasonCode,
            'last_status_changed_at' => now(),
        ]);

        return $subscription;
    }

    /**
     * Apply a status transition + the matching lifecycle timestamp, then emit the
     * status-specific event.
     *
     * @param  array<string,mixed>  $extra
     */
    public function transitionStatus(Subscription $subscription, string $status, array $extra = [], ?string $eventType = null): Subscription
    {
        // SUB-LM-01 transition map: refuse illegal jumps at the single write point,
        // whoever the caller is (workflow handler, dunning, a BIL-01 state callback).
        // The canonical rule this guards: TERMINATED never resurrects.
        if (! Subscription::canTransition($subscription->status_code, $status)) {
            throw DomainException::ruleRejected(
                'TRANSITION_NOT_ALLOWED',
                "SUB-LM-01: {$subscription->status_code} → {$status} is not a legal transition.",
            );
        }

        return DB::transaction(function () use ($subscription, $status, $extra, $eventType) {
            // Committing to a rest state clears the transient transition marker.
            $attrs = array_merge([
                'status_code' => $status,
                'current_transition_type' => null,
                'last_status_changed_at' => now(),
            ], $extra);

            $attrs += match ($status) {
                Subscription::ACTIVE => ['activated_at' => $subscription->activated_at ?? now()],
                Subscription::SUSPENDED, Subscription::PAUSED => ['suspended_at' => now()],
                Subscription::TERMINATED => ['terminated_at' => now()],
                default => [],
            };

            $subscription->update($attrs);

            $type = $eventType ?? match ($status) {
                Subscription::ACTIVE => SubscriptionEvents::ACTIVATED,
                Subscription::SUSPENDED => SubscriptionEvents::SUSPENDED,
                Subscription::PAUSED => SubscriptionEvents::PAUSED,
                Subscription::TERMINATED => SubscriptionEvents::TERMINATED,
                default => SubscriptionEvents::STATUS_CHANGED,
            };

            $this->emit($type, $subscription, [
                'subscriptionId' => $subscription->subscription_id,
                'status' => $status,
            ]);

            return $subscription;
        });
    }

    /** @param array<string,mixed> $payload */
    private function emit(string $type, Subscription $subscription, array $payload): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: SubscriptionEvents::TOPIC,
            payload: $payload,
            aggregateType: 'Subscription',
            aggregateId: $subscription->subscription_id,
        ));
    }
}
