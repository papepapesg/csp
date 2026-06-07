<?php

namespace Modules\Subscription\Services;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Illuminate\Support\Facades\DB;
use Modules\Subscription\Events\SubscriptionEvents;
use Modules\Subscription\Models\Subscription;

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
     * Apply a status transition + the matching lifecycle timestamp, then emit the
     * status-specific event.
     *
     * @param  array<string,mixed>  $extra
     */
    public function transitionStatus(Subscription $subscription, string $status, array $extra = [], ?string $eventType = null): Subscription
    {
        return DB::transaction(function () use ($subscription, $status, $extra, $eventType) {
            $attrs = array_merge([
                'status_code' => $status,
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
