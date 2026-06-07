<?php

namespace Modules\Subscription\Workflow;

use App\Foundation\Rules\RuleEngine;
use Modules\Catalog\Models\Package;
use Modules\Catalog\Models\PackageVersion;
use Modules\Subscription\Models\Subscription;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * SUB-WF-UPGRADE-01 / DOWNGRADE-01 validate-preconditions step. Builds the
 * target-package facts (currency match, price delta vs source, target ACTIVE,
 * same HomePass) and runs the operator-scoped rule package (ruleSet from node
 * config). Emits {eligible}; FUL-03 reconfiguration is downstream.
 *   config: { ruleSet: 'rules.subscription.upgrade' }
 */
class ValidatePackageChangeHandler implements TaskHandler
{
    public function __construct(private readonly RuleEngine $rules) {}

    public function topic(): string
    {
        return 'sub.validate-package-change';
    }

    public function label(): string
    {
        return 'Subscription: Validate package change (rules)';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $subscription = Subscription::query()->find($context->businessKey());
        if (! $subscription) {
            return TaskResult::fail('Subscription not found', retryable: false);
        }

        $targetPackageRef = $context->var('targetPackageRef');
        $target = $targetPackageRef ? Package::query()->find($targetPackageRef) : null;
        if (! $target) {
            return TaskResult::success(['eligible' => false, 'eligibilityReason' => 'UNKNOWN_TARGET_PACKAGE']);
        }

        $sourceVersion = $subscription->package_version_id ? PackageVersion::query()->find($subscription->package_version_id) : null;
        $targetVersion = $target->current_version_id ? PackageVersion::query()->find($target->current_version_id) : null;

        $sourcePrice = (float) ($sourceVersion->price ?? 0);
        $targetPrice = (float) ($targetVersion->price ?? 0);

        $facts = [
            'statusCode' => $subscription->status_code,
            'sourceCurrency' => $sourceVersion->currency ?? $subscription->currency,
            'targetCurrency' => $targetVersion->currency ?? $subscription->currency,
            'sourcePrice' => $sourcePrice,
            'targetPrice' => $targetPrice,
            'priceDelta' => round($targetPrice - $sourcePrice, 2),
            'targetPackageStatus' => $target->status,
            'sameHomePass' => true, // upgrade/downgrade stay on the same HomePass
        ];

        $cfg = $context->config();
        $result = $this->rules->assess($cfg['ruleSet'] ?? 'rules.subscription.upgrade', $facts);

        return TaskResult::success([
            'eligible' => $result['decision']['eligible'] ?? true,
            'eligibilityRuleId' => $result['decision']['ruleId'] ?? null,
            'eligibilityReason' => $result['decision']['decisionCode'] ?? null,
            'validationErrors' => $result['validationErrors'],
            'targetPackageVersionId' => $targetVersion->id ?? null,
            'priceDelta' => $facts['priceDelta'],
            'recipient' => $subscription->customer_id,
        ]);
    }
}
