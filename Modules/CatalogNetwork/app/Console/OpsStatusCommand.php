<?php

namespace Modules\Catalog\Network\Console;

use App\Foundation\Operations\OpsStatusCommand as BaseOpsStatusCommand;
use Modules\Catalog\Network\Models\HomePass;

class OpsStatusCommand extends BaseOpsStatusCommand
{
    protected $signature = 'sophix:catalog-network:ops-status {--operator=} {--json} {--fail-on-alert}';
    protected $description = 'Review HomePass and coverage catalog quality (read-only)';

    protected function moduleLabel(): string { return 'Catalog Network'; }

    protected function metrics(?string $operator): array
    {
        $scope = fn ($q) => $operator ? $q->where('operator_code', $operator) : $q;

        return [
            ['key' => 'draft_homepasses', 'label' => 'HomePasses still in draft', 'count' => $scope(HomePass::query())->where('status', HomePass::STATUS_DRAFT)->count(), 'severity' => 'info'],
            ['key' => 'serviceable_without_coordinates', 'label' => 'Serviceable without coordinates', 'count' => $scope(HomePass::query())->where('status', HomePass::STATUS_SERVICEABLE)->where(fn ($q) => $q->whereNull('geo_lat')->orWhereNull('geo_lng'))->count(), 'severity' => 'warning'],
            ['key' => 'serviceable_without_path', 'label' => 'Serviceable without network path', 'count' => $scope(HomePass::query())->where('status', HomePass::STATUS_SERVICEABLE)->whereNull('network_path')->count(), 'severity' => 'warning'],
        ];
    }
}
