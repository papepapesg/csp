<?php

namespace Modules\Subscription\Workflow;

use App\Foundation\Rules\RuleEngine;
use Modules\Catalog\Network\Models\HomePass;
use Modules\Catalog\Plm\Models\Package;
use Modules\Catalog\Plm\Models\PackageVersion;
use Modules\Subscription\Models\Subscription;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * SUB-WF-RELOCATION-01 / MIGRATION-01 validate-preconditions step. Validates the
 * target HomePass (must be SERVICEABLE and differ from source) and, for migration,
 * the technology change. Runs the operator-scoped rule package (ruleSet from node
 * config) and emits {eligible}. Physical work (WO-01 SHIFTING) + FUL is downstream.
 *   config: { ruleSet: 'rules.subscription.relocation' }
 */
class ValidateHomePassChangeHandler implements TaskHandler
{
    public function __construct(private readonly RuleEngine $rules) {}

    public function topic(): string
    {
        return 'sub.validate-homepass-change';
    }

    public function label(): string
    {
        return 'Subscription: Validate HomePass change (rules)';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $subscription = Subscription::query()->find($context->businessKey());
        if (! $subscription) {
            return TaskResult::fail('Subscription not found', retryable: false);
        }

        $targetHomepassId = $context->var('targetHomepassId');
        $target = $targetHomepassId ? HomePass::query()->find($targetHomepassId) : null;
        if (! $target) {
            return TaskResult::success(['eligible' => false, 'eligibilityReason' => 'UNKNOWN_TARGET_HOMEPASS']);
        }

        $source = HomePass::query()->find($subscription->homepass_id);

        // Optional target package (migration may change technology + package).
        $targetPackageRef = $context->var('targetPackageRef');
        $targetPackage = $targetPackageRef ? Package::query()->find($targetPackageRef) : null;
        $targetVersion = $targetPackage?->current_version_id ? PackageVersion::query()->find($targetPackage->current_version_id) : null;

        $facts = [
            'statusCode' => $subscription->status_code,
            'targetHomepassStatus' => $target->status,
            'sameHomePass' => $target->id === $subscription->homepass_id,
            'sourceTechnology' => $source->technology ?? null,
            'targetTechnology' => $target->technology ?? null,
            'sameTechnology' => ($source->technology ?? null) === ($target->technology ?? null),
            'targetPackageStatus' => $targetPackage->status ?? 'ACTIVE',
        ];

        $cfg = $context->config();
        $result = $this->rules->assess($cfg['ruleSet'] ?? 'rules.subscription.relocation', $facts);

        return TaskResult::success([
            'eligible' => $result['decision']['eligible'] ?? true,
            'eligibilityReason' => $result['decision']['decisionCode'] ?? null,
            'validationErrors' => $result['validationErrors'],
            'targetPackageVersionId' => $targetVersion->id ?? null,
            'recipient' => $subscription->customer_id,
        ]);
    }
}
