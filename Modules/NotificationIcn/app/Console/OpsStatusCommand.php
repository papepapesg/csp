<?php

namespace Modules\Notification\Icn\Console;

use App\Foundation\Operations\OpsStatusCommand as BaseOpsStatusCommand;
use Modules\Notification\Models\Icn\StaffNotification;

class OpsStatusCommand extends BaseOpsStatusCommand
{
    protected $signature = 'sophix:icn:ops-status {--operator=} {--json} {--fail-on-alert}';
    protected $description = 'Review staff notification acknowledgement and expiry queues (read-only)';

    protected function moduleLabel(): string { return 'Internal Communications'; }

    protected function metrics(?string $operator): array
    {
        $scope = fn ($q) => $operator ? $q->where('operator_code', $operator) : $q;

        return [
            ['key' => 'processing', 'label' => 'Still processing', 'count' => $scope(StaffNotification::query())->where('status', StaffNotification::PROCESSING)->count(), 'severity' => 'warning'],
            ['key' => 'ack_overdue', 'label' => 'Dispatched past acknowledgement window', 'count' => $scope(StaffNotification::query())->where('status', StaffNotification::DISPATCHED)->whereNotNull('expires_at')->where('expires_at', '<', now())->count(), 'severity' => 'critical'],
            ['key' => 'expired', 'label' => 'Expired notifications', 'count' => $scope(StaffNotification::query())->where('status', StaffNotification::EXPIRED)->count(), 'severity' => 'info'],
        ];
    }
}
