<?php

namespace Modules\Billing\Adjustments\Console;

use App\Foundation\Operations\OpsStatusCommand as BaseOpsStatusCommand;
use Modules\Billing\Adjustments\Models\AdjustmentRequest;
use Modules\Billing\Adjustments\Models\BulkReversalBatch;

class OpsStatusCommand extends BaseOpsStatusCommand
{
    protected $signature = 'sophix:billing-adjustments:ops-status {--operator=} {--json} {--fail-on-alert}';
    protected $description = 'Review adjustment approval and application queues (read-only)';

    protected function moduleLabel(): string { return 'Billing Adjustments'; }

    protected function metrics(?string $operator): array
    {
        $scope = fn ($q) => $operator ? $q->where('operator_code', $operator) : $q;

        return [
            ['key' => 'awaiting_approval', 'label' => 'Awaiting approval', 'count' => $scope(AdjustmentRequest::query())->whereIn('status', AdjustmentRequest::OPEN_STATUSES)->count(), 'severity' => 'info'],
            ['key' => 'application_failed', 'label' => 'Application failed', 'count' => $scope(AdjustmentRequest::query())->where('status', AdjustmentRequest::APPLICATION_FAILED)->count(), 'severity' => 'critical', 'hint' => 'Inspect the request and use its retry-application operation after resolving the cause.'],
            ['key' => 'bulk_reversal_approval', 'label' => 'Bulk reversals awaiting approval', 'count' => $scope(BulkReversalBatch::query())->where('status', BulkReversalBatch::PENDING_APPROVAL)->count(), 'severity' => 'warning'],
        ];
    }
}
