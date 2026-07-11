<?php

namespace Modules\Catalog\Tax\Console;

use App\Foundation\Operations\OpsStatusCommand as BaseOpsStatusCommand;
use Modules\Catalog\Tax\Models\TaxRule;

class OpsStatusCommand extends BaseOpsStatusCommand
{
    protected $signature = 'sophix:catalog-tax:ops-status {--operator=} {--json} {--fail-on-alert}';
    protected $description = 'Review tax catalog validity and effective dates (read-only)';

    protected function moduleLabel(): string { return 'Catalog Tax'; }

    protected function metrics(?string $operator): array
    {
        $scope = fn ($q) => $operator ? $q->where('operator_code', $operator) : $q;

        return [
            ['key' => 'invalid_rates', 'label' => 'Rules with invalid rates', 'count' => $scope(TaxRule::query())->where(fn ($q) => $q->where('rate', '<', 0)->orWhere('rate', '>', 1))->count(), 'severity' => 'critical'],
            ['key' => 'invalid_windows', 'label' => 'Rules with invalid effective window', 'count' => $scope(TaxRule::query())->whereNotNull('effective_from')->whereNotNull('effective_until')->whereColumn('effective_until', '<', 'effective_from')->count(), 'severity' => 'critical'],
            ['key' => 'expired_rules', 'label' => 'Rules past effective end', 'count' => $scope(TaxRule::query())->whereNotNull('effective_until')->where('effective_until', '<', now())->count(), 'severity' => 'info'],
        ];
    }
}
