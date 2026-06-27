<?php

namespace Modules\Subscription\Listeners;

use App\Foundation\Events\OutboxEventPublished;
use Modules\Billing\Intent\Models\BillingIntent;
use Modules\Billing\Intent\Services\BillingIntentService;
use Modules\Billing\Wallet\Services\WalletService;
use Modules\Workflow\Engine\WorkflowEngine;

/**
 * Prepaid pay-first gate release. When a prepaid subscription's wallet is topped up
 * (WalletToppedUp), re-attempt settlement of any still-PENDING wallet-settled billing
 * intent for that subscription. If the balance now covers the fee, debit it, confirm
 * the intent, and correlate `sub-payment-confirmed` so the parked operation resumes
 * from AWAITING_PAYMENT — the prepaid mirror of ConfirmBillingIntentOnPayment.
 */
class ConfirmPrepaidIntentOnTopup
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly BillingIntentService $intents,
        private readonly WorkflowEngine $engine,
    ) {}

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

        BillingIntent::query()
            ->where('subscription_id', $subscriptionId)
            ->where('settlement_channel', 'WALLET')
            ->where('status', BillingIntent::PENDING)
            ->get()
            ->each(function (BillingIntent $intent) use ($subscriptionId) {
                $result = $this->wallets->settleFromWallets(
                    $subscriptionId, (float) $intent->amount, $intent->intent_type, $intent->operator_code, $intent->operation_id,
                );
                if (! $result['settled']) {
                    return; // still short — wait for the next top-up
                }
                $this->intents->confirm($intent);
                $this->engine->correlateMessage('sub-payment-confirmed', $subscriptionId, [
                    'paymentConfirmed' => true, 'billingIntentId' => $intent->intent_id,
                ]);
            });
    }
}
