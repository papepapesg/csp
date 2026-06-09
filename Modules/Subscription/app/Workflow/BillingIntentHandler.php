<?php

namespace Modules\Subscription\Workflow;

use Modules\Billing\Models\BillingIntent;
use Modules\Billing\Services\BillingIntentService;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Models\SubscriptionOperation;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * SUB-WF bil01-emit-intent step. In the commit window, raises the operation's
 * billable-event intent (proration delta / pause / reconnection fee / deposit
 * refund) through BIL-01. For a pay-first chargeable intent the flow then parks on
 * AWAITING_PAYMENT until the fee invoice is settled; otherwise it proceeds.
 * Narrates BILLING_CALL (and AWAITING_PAYMENT when gated).
 *   config: { intentType: 'PRORATION', payFirst: true, amountVar: 'priceDelta', fixedAmount: 0 }
 */
class BillingIntentHandler implements TaskHandler
{
    public function __construct(private readonly BillingIntentService $intents) {}

    public function topic(): string
    {
        return 'sub.billing-intent';
    }

    public function label(): string
    {
        return 'Subscription: Billing intent (BIL-01)';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $subscription = Subscription::query()->find($context->businessKey());
        if (! $subscription) {
            return TaskResult::fail('Subscription not found', retryable: false);
        }
        $operationId = $context->var('operationId');
        SubscriptionOperation::narrate($operationId, SubscriptionOperation::BILLING_CALL);

        $cfg = $context->config();
        $amount = $cfg['amountVar'] ? (float) $context->var($cfg['amountVar'], 0) : (float) ($cfg['fixedAmount'] ?? 0);
        $payFirst = (bool) ($cfg['payFirst'] ?? false);

        $intent = $this->intents->emit([
            'subscription_id' => $subscription->subscription_id,
            'account_id' => $subscription->account_id,
            'operator_code' => $subscription->operator_code,
            'billing_mode' => $subscription->billing_mode,   // PREPAID settles from the wallet, POSTPAID raises an invoice
            'operation_id' => $operationId,
            'intent_type' => $cfg['intentType'] ?? 'PRORATION',
            'amount' => $amount,
            'currency' => $subscription->currency,
            'pay_first' => $payFirst,
            'description' => ($cfg['intentType'] ?? 'CHARGE').' for '.$context->var('operationKind'),
        ]);

        $paymentRequired = $intent->status === BillingIntent::PENDING && (float) $intent->amount > 0;
        if ($paymentRequired) {
            SubscriptionOperation::narrate($operationId, SubscriptionOperation::AWAITING_PAYMENT);
        }

        return TaskResult::success(['billingIntentId' => $intent->intent_id, 'paymentRequired' => $paymentRequired]);
    }
}
