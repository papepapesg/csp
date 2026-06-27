<?php

namespace Modules\Subscription\Workflow;

use Modules\Billing\Intent\Models\BillingIntent;
use Modules\Billing\Intent\Services\BillingIntentService;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Models\SubscriptionOperation;
use Modules\Workflow\Contracts\Io;
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

    /** @return array<int,array<string,mixed>> */
    public function inputs(): array
    {
        return [
            Io::in('amount', Io::NUMBER, 'Amount to charge. Wire this from an upstream output (e.g. validate.priceDelta) or set a fixed value.'),
            Io::in('intentType', Io::ENUM, 'Billable-event category raised through BIL-01.', false, 'PRORATION', ['PRORATION', 'PAUSE_FEE', 'RECONNECTION_FEE', 'DEPOSIT_REFUND']),
            Io::in('payFirst', Io::BOOLEAN, 'When true and the amount is chargeable, the flow parks on payment before fulfilling.', false, false),
            // Legacy bindings (pre-IO flows): read the amount from a named variable or a fixed value.
            Io::in('amountVar', Io::STRING, 'Legacy: name of the variable holding the amount (used only when amount is not wired).'),
            Io::in('fixedAmount', Io::NUMBER, 'Legacy: fixed amount when neither amount nor amountVar is set.', false, 0),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function outputs(): array
    {
        return [
            Io::out('billingIntentId', Io::STRING, 'Id of the raised BIL-01 intent.'),
            Io::out('paymentRequired', Io::BOOLEAN, 'True when an unpaid chargeable balance was raised — a gateway can branch to await payment.'),
        ];
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
        // Prefer the resolved 'amount' input (a wire or literal). Fall back to the legacy
        // amountVar/fixedAmount config so pre-IO flows keep working unchanged.
        $legacyAmount = $cfg['amountVar'] ? (float) $context->var($cfg['amountVar'], 0) : (float) ($cfg['fixedAmount'] ?? 0);
        $amount = (float) $context->input('amount', $legacyAmount);
        $payFirst = (bool) $context->input('payFirst', $cfg['payFirst'] ?? false);
        $intentType = (string) $context->input('intentType', $cfg['intentType'] ?? 'PRORATION');

        $intent = $this->intents->emit([
            'subscription_id' => $subscription->subscription_id,
            'account_id' => $subscription->account_id,
            'operator_code' => $subscription->operator_code,
            'billing_mode' => $subscription->billing_mode,   // PREPAID settles from the wallet, POSTPAID raises an invoice
            'operation_id' => $operationId,
            'intent_type' => $intentType,
            'amount' => $amount,
            'currency' => $subscription->currency,
            'pay_first' => $payFirst,
            'description' => $intentType.' for '.$context->var('operationKind'),
        ]);

        $paymentRequired = $intent->status === BillingIntent::PENDING && (float) $intent->amount > 0;
        if ($paymentRequired) {
            SubscriptionOperation::narrate($operationId, SubscriptionOperation::AWAITING_PAYMENT);
        }

        return TaskResult::success(['billingIntentId' => $intent->intent_id, 'paymentRequired' => $paymentRequired]);
    }
}
