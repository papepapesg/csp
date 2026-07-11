<?php

namespace Modules\Osr\Procurement\Console;

use App\Foundation\Operations\OpsStatusCommand as BaseOpsStatusCommand;
use Modules\Osr\Procurement\Models\PurchaseOrder;

class OpsStatusCommand extends BaseOpsStatusCommand
{
    protected $signature = 'sophix:procurement:ops-status {--operator=} {--json} {--fail-on-alert}';
    protected $description = 'Review procurement approval and ageing queues (read-only)';

    protected function moduleLabel(): string { return 'OSR Procurement'; }

    protected function metrics(?string $operator): array
    {
        $scope = fn ($q) => $operator ? $q->where('operator_code', $operator) : $q;

        return [
            ['key' => 'draft', 'label' => 'Draft purchase orders', 'count' => $scope(PurchaseOrder::query())->where('status', PurchaseOrder::DRAFT)->count(), 'severity' => 'info'],
            ['key' => 'pending_approval', 'label' => 'Pending approval', 'count' => $scope(PurchaseOrder::query())->where('status', PurchaseOrder::PENDING_APPROVAL)->count(), 'severity' => 'warning'],
            ['key' => 'stale_approval', 'label' => 'Pending approval over 48 hours', 'count' => $scope(PurchaseOrder::query())->where('status', PurchaseOrder::PENDING_APPROVAL)->where('created_at', '<', now()->subDays(2))->count(), 'severity' => 'critical'],
        ];
    }
}
