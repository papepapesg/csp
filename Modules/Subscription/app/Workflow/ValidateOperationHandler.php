<?php

namespace Modules\Subscription\Workflow;

use App\Foundation\Rules\RuleEngine;
use Modules\Subscription\Models\Subscription;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * Generic SUB-WF validate-preconditions step for pause/resume. The required
 * source status and the rule package come from the node config, so one handler
 * serves many operations (config, not code).
 *   config: { ruleSet: 'rules.subscription.pause', requiredStatus: 'ACTIVE' }
 */
class ValidateOperationHandler implements TaskHandler
{
    public function __construct(private readonly RuleEngine $rules) {}

    public function topic(): string
    {
        return 'sub.validate-operation';
    }

    public function label(): string
    {
        return 'Subscription: Validate operation (rules)';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $cfg = $context->config();
        $subscription = Subscription::query()->find($context->businessKey());
        if (! $subscription) {
            return TaskResult::fail('Subscription not found', retryable: false);
        }

        $required = $cfg['requiredStatus'] ?? null;
        if ($required && $subscription->status_code !== $required) {
            return TaskResult::success(['eligible' => false, 'eligibilityReason' => 'INVALID_SOURCE_STATUS']);
        }

        $result = $this->rules->assess($cfg['ruleSet'] ?? 'rules.subscription.common', [
            'statusCode' => $subscription->status_code,
            'operationKind' => $context->var('operationKind'),
            'reasonCode' => $context->var('reasonCode'),
        ]);

        return TaskResult::success([
            'eligible' => $result['decision']['eligible'] ?? true,
            'eligibilityRuleId' => $result['decision']['ruleId'] ?? null,
            'validationErrors' => $result['validationErrors'],
            'recipient' => $context->var('recipient'),
        ]);
    }
}
