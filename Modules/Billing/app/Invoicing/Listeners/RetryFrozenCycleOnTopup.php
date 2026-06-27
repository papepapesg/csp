<?php

namespace Modules\Billing\Invoicing\Listeners;

use App\Foundation\Events\OutboxEventPublished;
use App\Foundation\Support\Context;
use Modules\Billing\Invoicing\Services\CycleCloseService;
use Modules\Subscription\Models\Subscription;

/**
 * BIL-03 R-BIL-03-W-4: a prepaid cycle that missed payment has its anchor frozen
 * at the missed boundary. When the wallet is topped up (WalletToppedUp), retry
 * the cycle close — if the balance now covers the charge, the cycle settles and
 * the anchor advances (CycleActivated), unfreezing the subscription.
 */
class RetryFrozenCycleOnTopup
{
    public function __construct(private readonly CycleCloseService $cycles) {}

    public function handle(OutboxEventPublished $published): void
    {
        $event = $published->event;
        if ($event->event_type !== 'WalletToppedUp') {
            return;
        }
        $subscriptionId = $event->payload['subscriptionId'] ?? null;
        if (! $subscriptionId) {
            return;
        }

        $subscription = Subscription::query()->find($subscriptionId);
        // Only act on a genuinely frozen cycle (boundary reached, not yet closed).
        if (! $subscription || ! $subscription->current_cycle_end || $subscription->current_cycle_end->isFuture()) {
            return;
        }

        Context::setOperatorCode($subscription->operator_code);
        $this->cycles->closeCycle($subscriptionId);
    }
}
