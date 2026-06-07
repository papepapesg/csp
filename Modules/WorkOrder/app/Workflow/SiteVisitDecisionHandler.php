<?php

namespace Modules\WorkOrder\Workflow;

use App\Foundation\Rules\RuleEngine;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;
use Modules\WorkOrder\Models\WoJobTypeCatalog;
use Modules\WorkOrder\Models\WorkOrder;

/**
 * WO-01-FLOW-SUPPORT site-visit-decision gateway worker. Reads the job type's
 * requires_site_visit from the catalog and runs rules.workorder.site-visit-decision,
 * returning REQUIRES_VISIT / NO_SITE_VISIT. Operators tune the rule (e.g. VIP
 * always visits) without changing the flow.
 */
class SiteVisitDecisionHandler implements TaskHandler
{
    public function __construct(private readonly RuleEngine $rules) {}

    public function topic(): string
    {
        return 'wo.site-visit-decision';
    }

    public function label(): string
    {
        return 'WO: Site-visit decision (rules)';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $wo = WorkOrder::query()->find($context->businessKey());
        if (! $wo) {
            return TaskResult::fail('Work order not found', retryable: false);
        }

        $job = WoJobTypeCatalog::query()
            ->where('operator_code', $wo->operator_code)
            ->where('job_type_code', $wo->job_type_code)
            ->first();
        $requiresSiteVisit = $job?->requires_site_visit ?? true;

        $decision = $this->rules->evaluate('rules.workorder.site-visit-decision', [
            'requiresSiteVisit' => $requiresSiteVisit,
            'jobTypeCode' => $wo->job_type_code,
        ]);

        return TaskResult::success(['siteVisitDecision' => $decision['decision'] ?? 'REQUIRES_VISIT']);
    }
}
