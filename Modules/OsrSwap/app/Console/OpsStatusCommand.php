<?php

namespace Modules\Osr\Swap\Console;

use App\Foundation\Operations\OpsStatusCommand as BaseOpsStatusCommand;
use Modules\Osr\Swap\Models\EquipmentSwapRequest;

class OpsStatusCommand extends BaseOpsStatusCommand
{
    protected $signature = 'sophix:osr-swap:ops-status {--operator=} {--json} {--fail-on-alert}';
    protected $description = 'Review equipment swap workflow queues (read-only)';

    protected function moduleLabel(): string { return 'OSR Swap/RMA'; }

    protected function metrics(?string $operator): array
    {
        $scope = fn ($q) => $operator ? $q->where('operator_code', $operator) : $q;

        return [
            ['key' => 'awaiting_slot', 'label' => 'Awaiting field slot', 'count' => $scope(EquipmentSwapRequest::query())->where('status', EquipmentSwapRequest::AWAITING_SLOT)->count(), 'severity' => 'warning'],
            ['key' => 'in_progress', 'label' => 'Field visit in progress', 'count' => $scope(EquipmentSwapRequest::query())->where('status', EquipmentSwapRequest::FIELD_VISIT_IN_PROGRESS)->count(), 'severity' => 'info'],
            ['key' => 'failed', 'label' => 'Failed swaps', 'count' => $scope(EquipmentSwapRequest::query())->where('status', EquipmentSwapRequest::FAILED)->count(), 'severity' => 'critical'],
            ['key' => 'without_recovery', 'label' => 'Completed without equipment recovery', 'count' => $scope(EquipmentSwapRequest::query())->where('status', EquipmentSwapRequest::COMPLETED_WITHOUT_RECOVERY)->count(), 'severity' => 'warning'],
        ];
    }
}
