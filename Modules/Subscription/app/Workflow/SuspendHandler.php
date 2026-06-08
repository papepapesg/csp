<?php

namespace Modules\Subscription\Workflow;

use Modules\Subscription\Events\SubscriptionEvents;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Models\SubscriptionOperation;
use Modules\Subscription\Models\SubscriptionSuspendNpConfig;
use Modules\Subscription\Services\SubscriptionService;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * SUB-WF-SUSPEND-NP-01 commit. R-SUSPEND-NP-S-2: ACTIVE -> PENDING_SUSPEND_NP ->
 * SUSPENDED. R-SUSPEND-NP-M-3: clears active_restrictions[] (suspension revokes all
 * service). Narrates COMMITTING_FINAL_STATE.
 */
class SuspendHandler implements TaskHandler
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function topic(): string
    {
        return 'sub.suspend';
    }

    public function label(): string
    {
        return 'Subscription: Commit non-payment suspension (SUSPENDED)';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $subscription = Subscription::query()->find($context->businessKey());
        if (! $subscription) {
            return TaskResult::fail('Subscription not found', retryable: false);
        }
        SubscriptionOperation::narrate($context->var('operationId'), SubscriptionOperation::COMMITTING_FINAL_STATE);

        $debt = (float) $context->var('outstandingDebtAmount', 0);
        $config = SubscriptionSuspendNpConfig::forOperator($subscription->operator_code);
        $debtTier = $config?->debtTier($debt) ?? 'STANDARD';

        if ($subscription->status_code !== Subscription::SUSPENDED && ! $subscription->isTerminal()) {
            $this->subscriptions->transitionStatus($subscription, Subscription::SUSPENDED, [
                'current_transition_reason_code' => $context->var('dunningReasonCode', $context->var('reasonCode', 'DUNNING_LEVEL_3_NON_PAYMENT')),
                'active_restrictions' => [], // R-SUSPEND-NP-M-3: suspension revokes all service
            ], SubscriptionEvents::SUSPENDED_NON_PAYMENT);

            // R-SUSPEND-NP-M-2: the pause-history row carries the full dunning context.
            $this->subscriptions->openPausePeriod($subscription, [
                'origin_intent' => 'SUSPEND_NP',
                'system_managed' => true,
                'pause_reason_code' => $context->var('reasonCode', 'NON_PAYMENT'),
                'dunning_reason_code' => $context->var('dunningReasonCode'),
                'dunning_cycle_reference' => $context->var('dunningCycleReference'),
                'dunning_escalation_level' => $context->var('dunningEscalationLevel', $context->var('dunningLevel')),
                'outstanding_debt_amount' => $debt ?: null,
                'outstanding_debt_currency' => $context->var('outstandingDebtCurrency'),
                'pause_correlation_id' => $context->var('operationId'),
            ]);
        }

        // §6: SubscriptionSuspendedForNonPayment payload tiers HIGH-value churn risk.
        return TaskResult::success(['subscriptionStatus' => Subscription::SUSPENDED, 'debtAmountTier' => $debtTier]);
    }
}
