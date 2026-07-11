<?php

namespace Modules\Catalog\Discount\Console;

use App\Foundation\Operations\OpsStatusCommand as BaseOpsStatusCommand;
use Modules\Catalog\Discount\Models\PromoCampaign;

class OpsStatusCommand extends BaseOpsStatusCommand
{
    protected $signature = 'sophix:catalog-discount:ops-status {--operator=} {--json} {--fail-on-alert}';
    protected $description = 'Review promotion campaign lifecycle queues (read-only)';

    protected function moduleLabel(): string { return 'Catalog Discount'; }

    protected function metrics(?string $operator): array
    {
        $scope = fn ($q) => $operator ? $q->where('operator_code', $operator) : $q;

        return [
            ['key' => 'ready_for_review', 'label' => 'Ready for review', 'count' => $scope(PromoCampaign::query())->where('status', PromoCampaign::READY_FOR_REVIEW)->count(), 'severity' => 'info'],
            ['key' => 'approved_due', 'label' => 'Approved and due to start', 'count' => $scope(PromoCampaign::query())->where('status', PromoCampaign::APPROVED)->where('starts_at', '<=', now())->count(), 'severity' => 'warning'],
            ['key' => 'active_expired', 'label' => 'Active past end date', 'count' => $scope(PromoCampaign::query())->where('status', PromoCampaign::ACTIVE)->whereNotNull('ends_at')->where('ends_at', '<', now())->count(), 'severity' => 'critical', 'hint' => 'Run the campaign expiry maintenance after validating effective dates.'],
        ];
    }
}
