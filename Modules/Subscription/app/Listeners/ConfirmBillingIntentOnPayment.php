<?php

namespace Modules\Subscription\Listeners;

use App\Foundation\Events\OutboxEventPublished;
use Modules\Billing\Intent\Models\BillingIntent;
use Modules\Billing\Intent\Services\BillingIntentService;
use Modules\Workflow\Engine\WorkflowEngine;

/**
 * Pay-first gate release. When a fee/proration invoice raised by a billing intent
 * is settled (InvoicePaid), confirm the intent and correlate the
 * `sub-payment-confirmed` message so the parked operation workflow resumes from
 * its AWAITING_PAYMENT wait. Inbox-style idempotent (only acts on PENDING intents).
 */
class ConfirmBillingIntentOnPayment
{
    public function __construct(
        private readonly BillingIntentService $intents,
        private readonly WorkflowEngine $engine,
    ) {}

    public function handle(OutboxEventPublished $published): void
    {
        $event = $published->event;
        if ($event->event_type !== 'InvoicePaid') {
            return;
        }
        $invoiceId = $event->payload['invoiceId'] ?? null;
        if (! $invoiceId) {
            return;
        }

        BillingIntent::query()->where('invoice_id', $invoiceId)->where('status', BillingIntent::PENDING)
            ->get()->each(function (BillingIntent $intent) {
                $this->intents->confirm($intent);
                // Resume the parked operation flow (correlation key = subscription_id).
                $this->engine->correlateMessage('sub-payment-confirmed', $intent->subscription_id, [
                    'paymentConfirmed' => true, 'billingIntentId' => $intent->intent_id,
                ]);
            });
    }
}
