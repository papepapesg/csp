<?php

namespace Modules\WorkOrder\Workflow;

use App\Foundation\Rules\RuleEngine;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * WO-01-FLOW-SUPPORT resolution-gate worker. Reads the WO's final_reason captured
 * by the field/desk agent and runs rules.workorder.resolution-gate, returning
 * RESOLVED / NOT_RESOLVED_ESCALATE / AREA_OUTAGE. Thresholds are operator-tunable.
 */
class ResolutionGateHandler implements TaskHandler
{
    public function __construct(private readonly RuleEngine $rules) {}

    public function topic(): string
    {
        return 'wo.resolution-gate';
    }

    public function label(): string
    {
        return 'WO: Resolution gate (rules)';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $decision = $this->rules->evaluate('rules.workorder.resolution-gate', [
            'finalReason' => $context->var('finalReason'),
        ]);

        return TaskResult::success(['resolutionDecision' => $decision['decision'] ?? 'RESOLVED']);
    }
}
