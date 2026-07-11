<?php

namespace Modules\Catalog\Rating\Console;

use App\Foundation\Operations\OpsStatusCommand as BaseOpsStatusCommand;
use Modules\Catalog\Rating\Models\VoiceTariffPlan;

class OpsStatusCommand extends BaseOpsStatusCommand
{
    protected $signature = 'sophix:catalog-rating:ops-status {--operator=} {--json} {--fail-on-alert}';
    protected $description = 'Review tariff catalog activation and expiry queues (read-only)';

    protected function moduleLabel(): string { return 'Catalog Rating'; }

    protected function metrics(?string $operator): array
    {
        $scope = fn ($q) => $operator ? $q->where('operator_code', $operator) : $q;

        return [
            ['key' => 'draft_plans', 'label' => 'Draft tariff plans', 'count' => $scope(VoiceTariffPlan::query())->where('status', VoiceTariffPlan::STATUS_DRAFT)->count(), 'severity' => 'info'],
            ['key' => 'active_expired', 'label' => 'Active plans past effective end', 'count' => $scope(VoiceTariffPlan::query())->where('status', VoiceTariffPlan::STATUS_ACTIVE)->whereNotNull('effective_to')->where('effective_to', '<', now())->count(), 'severity' => 'critical'],
            ['key' => 'active_without_start', 'label' => 'Active plans without effective start', 'count' => $scope(VoiceTariffPlan::query())->where('status', VoiceTariffPlan::STATUS_ACTIVE)->whereNull('effective_from')->count(), 'severity' => 'warning'],
        ];
    }
}
