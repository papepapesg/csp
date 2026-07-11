<?php

namespace Modules\Billing\Intent\Console;

use App\Foundation\Operations\OpsStatusCommand as BaseOpsStatusCommand;
use Modules\Billing\Intent\Models\BillingIntent;

class OpsStatusCommand extends BaseOpsStatusCommand
{
    protected $signature = 'sophix:billing-intent:ops-status {--operator=} {--json} {--fail-on-alert}';
    protected $description = 'Review unsettled and stale billing intents (read-only)';

    protected function moduleLabel(): string { return 'Billing Intent'; }

    protected function metrics(?string $operator): array
    {
        $scope = fn ($q) => $operator ? $q->where('operator_code', $operator) : $q;

        return [
            ['key' => 'pending', 'label' => 'Pending settlement', 'count' => $scope(BillingIntent::query())->where('status', BillingIntent::PENDING)->count(), 'severity' => 'info'],
            ['key' => 'stale_pending', 'label' => 'Pending for more than 24 hours', 'count' => $scope(BillingIntent::query())->where('status', BillingIntent::PENDING)->where('created_at', '<', now()->subDay())->count(), 'severity' => 'warning', 'hint' => 'Trace the payment or wallet settlement event for stale intents.'],
            ['key' => 'charged_unconfirmed', 'label' => 'Charged but unconfirmed', 'count' => $scope(BillingIntent::query())->where('status', BillingIntent::CHARGED)->count(), 'severity' => 'warning'],
        ];
    }
}
