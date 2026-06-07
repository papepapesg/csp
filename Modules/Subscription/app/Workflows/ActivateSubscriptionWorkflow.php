<?php

namespace Modules\Subscription\Workflows;

use App\Foundation\Errors\DomainException;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Models\SubscriptionOperation;
use Modules\Subscription\Services\SubscriptionService;

/**
 * SUB-WF-ACTIVATE-01 — drives a subscription PENDING_ACTIVATION -> ACTIVE.
 *
 * In the full design this coordinates FUL-03 service activation/provisioning;
 * here it performs the authoritative SUB-LM status transition and emits the
 * activation fact. Downstream provisioning reacts to SubscriptionActivated.
 */
class ActivateSubscriptionWorkflow implements SubscriptionWorkflow
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function kind(): string
    {
        return 'ACTIVATE';
    }

    public function handle(SubscriptionOperation $operation): string
    {
        /** @var Subscription $subscription */
        $subscription = $operation->subscription;

        if ($subscription->status_code === Subscription::ACTIVE) {
            return Subscription::ACTIVE; // already active — idempotent no-op
        }

        if ($subscription->isTerminal()) {
            throw DomainException::conflict('Cannot activate a terminated subscription.');
        }

        $this->subscriptions->transitionStatus($subscription, Subscription::ACTIVE, [
            'start_date' => $subscription->start_date ?? now()->toDateString(),
        ]);

        return Subscription::ACTIVE;
    }
}
