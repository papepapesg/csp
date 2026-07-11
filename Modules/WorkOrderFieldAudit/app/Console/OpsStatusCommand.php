<?php

namespace Modules\WorkOrder\FieldAudit\Console;

use App\Foundation\Operations\OpsStatusCommand as BaseOpsStatusCommand;
use Modules\WorkOrder\FieldAudit\Models\FieldAuditCampaign;
use Modules\WorkOrder\FieldAudit\Models\FieldAuditTask;

class OpsStatusCommand extends BaseOpsStatusCommand
{
    protected $signature = 'sophix:field-audit:ops-status {--operator=} {--json} {--fail-on-alert}';
    protected $description = 'Review field-audit campaign and discrepancy queues (read-only)';

    protected function moduleLabel(): string { return 'Work Order Field Audit'; }

    protected function metrics(?string $operator): array
    {
        $scope = fn ($q) => $operator ? $q->where('operator_code', $operator) : $q;

        return [
            ['key' => 'scheduled_overdue', 'label' => 'Scheduled campaigns past start', 'count' => $scope(FieldAuditCampaign::query())->where('status', FieldAuditCampaign::SCHEDULED)->where('scheduled_start_at', '<', now())->count(), 'severity' => 'warning'],
            ['key' => 'campaigns_past_end', 'label' => 'Open campaigns past end', 'count' => $scope(FieldAuditCampaign::query())->whereIn('status', [FieldAuditCampaign::IN_PROGRESS, FieldAuditCampaign::RECONCILING])->whereNotNull('scheduled_end_at')->where('scheduled_end_at', '<', now())->count(), 'severity' => 'critical'],
            ['key' => 'open_discrepancies', 'label' => 'Tasks with open discrepancies', 'count' => $scope(FieldAuditTask::query())->where('status', FieldAuditTask::DISCREPANCY_OPEN)->count(), 'severity' => 'warning'],
        ];
    }
}
