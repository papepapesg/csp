<?php

namespace Modules\Subscription\Workflows;

use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Models\SubscriptionOperation;
use Modules\Subscription\Services\SubscriptionService;

/**
 * SUB-WF-TERMINATE-01 — drives a subscription to TERMINATED (terminal).
 */
class TerminateSubscriptionWorkflow implements SubscriptionWorkflow
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function kind(): string
    {
        return 'TERMINATE';
    }

    public function handle(SubscriptionOperation $operation): string
    {
        $subscription = $operation->subscription;

        if ($subscription->isTerminal()) {
            return Subscription::TERMINATED; // idempotent
        }

        $reason = $operation->input['reasonCode'] ?? null;
        $this->subscriptions->transitionStatus($subscription, Subscription::TERMINATED, [
            'current_transition_reason_code' => $reason,
            'end_date' => now()->toDateString(),
        ]);

        return Subscription::TERMINATED;
    }
}
