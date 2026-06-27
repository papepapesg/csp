<?php

namespace Modules\Billing\Dunning\Listeners;

use App\Foundation\Events\OutboxEventPublished;
use App\Foundation\Support\Context;
use Modules\Billing\Dunning\Services\DunningService;
use Modules\Subscription\Models\Subscription;

/**
 * BIL-04 upstream event bridge: a prepaid CyclePaymentMissed enters dunning
 * (T-1); a voluntary SubscriptionPaused/Resumed pauses/resumes the dunning
 * episode (T-6); a WalletToppedUp drives prepaid recovery (R-8).
 */
class DunningEventBridge
{
    public function __construct(private readonly DunningService $dunning) {}

    public function handle(OutboxEventPublished $published): void
    {
        $event = $published->event;
        $payload = $event->payload ?? [];

        match ($event->event_type) {
            'CyclePaymentMissed' => $this->onCycleMissed($payload),
            'SubscriptionPaused' => $this->onPaused($payload, voluntary: true),
            'SubscriptionResumed' => $this->onResumed($payload),
            'WalletToppedUp' => $this->onTopup($payload),
            default => null,
        };
    }

    private function onCycleMissed(array $payload): void
    {
        $sub = $this->sub($payload['subscriptionId'] ?? null);
        if ($sub) {
            $this->dunning->enterFromCycleMissed($sub, (float) ($payload['amountDue'] ?? 0));
        }
    }

    private function onPaused(array $payload, bool $voluntary): void
    {
        // Only a customer-requested pause suspends dunning (a dunning-driven
        // suspension is SUSPEND_NP, a different reason).
        if (($payload['reasonCode'] ?? null) === 'NON_PAYMENT') {
            return;
        }
        $accountId = $payload['accountId'] ?? $this->sub($payload['subscriptionId'] ?? null)?->account_id;
        if ($accountId) {
            $this->dunning->pauseForVoluntaryPause($accountId);
        }
    }

    private function onResumed(array $payload): void
    {
        $accountId = $payload['accountId'] ?? $this->sub($payload['subscriptionId'] ?? null)?->account_id;
        if ($accountId) {
            $this->dunning->resumeFromVoluntaryPause($accountId);
        }
    }

    private function onTopup(array $payload): void
    {
        $sub = $this->sub($payload['subscriptionId'] ?? null);
        if ($sub) {
            Context::setOperatorCode($sub->operator_code);
            $this->dunning->recoverOnTopup($sub);
        }
    }

    private function sub(?string $id): ?Subscription
    {
        return $id ? Subscription::query()->find($id) : null;
    }
}
