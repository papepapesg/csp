<?php

namespace Modules\Subscription\Workflow;

use App\Foundation\Rules\RuleEngine;
use Modules\Billing\Invoicing\Models\Invoice;
use Modules\Subscription\Models\Subscription;
use Modules\Workflow\Contracts\Io;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * Toolbox step: decide whether a subscription may be activated.
 *
 * Fixed guards (terminated subscription) stay in code, but the configurable
 * POLICY — eligibility given the account's financial state — is delegated to the
 * data-driven rule engine (the 'activation.eligibility' decision table). The
 * gateway in the sub-activate flow branches on the `eligible` it returns, so an
 * operator can change activation policy in the Rules Studio with no code change.
 */
class ValidateActivationHandler implements TaskHandler
{
    public function __construct(private readonly RuleEngine $rules) {}

    public function topic(): string
    {
        return 'sub.validate-activation';
    }

    public function label(): string
    {
        return 'Subscription: Validate activation';
    }

    public function description(): string
    {
        return 'Decides whether a subscription may be activated. Hard guards (e.g. terminated) '
            .'stay fixed, but the eligibility policy is read from the \'activation.eligibility\' '
            .'decision table — so a gateway after this step can branch on the result, and an '
            .'operator can change activation policy in the Rules Studio without code.';
    }

    /** @return array<int,array<string,mixed>> */
    public function outputs(): array
    {
        return [
            Io::out('eligible', Io::BOOLEAN, 'Whether activation may proceed — wire a gateway off this to branch.'),
            Io::out('eligibilityReason', Io::STRING, 'Decision code when not eligible (e.g. PAY_FIRST_REQUIRED).'),
            Io::out('eligibilityRuleId', Io::STRING, 'Id of the decision-table rule that fired.'),
            Io::out('validationErrors', Io::OBJECT, 'Structured validation errors from the decision table (empty when eligible).'),
            Io::out('outstandingBalance', Io::NUMBER, 'Open balance computed for the account.'),
            Io::out('recipient', Io::STRING, 'Pass-through notification recipient carried for downstream steps.'),
        ];
    }

    public function handle(TaskContext $context): TaskResult
    {
        $subscription = Subscription::query()->find($context->businessKey());
        if (! $subscription) {
            return TaskResult::fail('Subscription not found', retryable: false);
        }

        // Fixed validation: a terminated subscription can never activate.
        if ($subscription->isTerminal()) {
            return TaskResult::success(['eligible' => false, 'eligibilityReason' => 'TERMINATED']);
        }

        // Gather activation precondition facts. (Outstanding balance would be read
        // through the BIL read API in a split deployment; in the monolith we read
        // BIL's invoice projection directly.)
        $outstanding = (float) Invoice::query()
            ->where('account_id', $subscription->account_id)
            ->whereIn('status', [Invoice::OPEN, Invoice::PARTIALLY_PAID, Invoice::OVERDUE])
            ->sum('amount_due');

        // Evaluate the design's rule package rules.subscription.activate
        // (SUB-WF-ACTIVATE-01 validate-preconditions). Operator-specific tables
        // override the default; results carry ruleIds (DROOLS-RES-1).
        $result = $this->rules->assess('rules.subscription.activate', [
            'statusCode' => $subscription->status_code,
            'outstandingBalance' => $outstanding,
            'billingMode' => $subscription->billing_mode,
        ]);
        $decision = $result['decision'];

        return TaskResult::success([
            'eligible' => $decision['eligible'] ?? true,
            'eligibilityReason' => $decision['decisionCode'] ?? null,
            'eligibilityRuleId' => $decision['ruleId'] ?? null,
            'validationErrors' => $result['validationErrors'],
            'outstandingBalance' => $outstanding,
            'recipient' => $context->var('recipient'),
        ]);
    }
}
