<?php

namespace Modules\Rules\Workflow;

use App\Foundation\Rules\RuleEngine;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * Toolbox step: evaluate a decision table and merge its outputs into the process
 * variables (so a downstream gateway can branch on configurable policy). The
 * rule set comes from node config; facts are the current process variables.
 */
class EvaluateRuleHandler implements TaskHandler
{
    public function __construct(private readonly RuleEngine $rules) {}

    public function topic(): string
    {
        return 'rules.evaluate';
    }

    public function label(): string
    {
        return 'Rules: Evaluate decision table';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $ruleSet = $context->config()['ruleSet'] ?? null;
        if (! $ruleSet) {
            return TaskResult::fail('rules.evaluate node has no ruleSet config', retryable: false);
        }

        $outputs = $this->rules->evaluate($ruleSet, $context->variables());

        return TaskResult::success($outputs);
    }
}
