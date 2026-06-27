<?php

namespace Modules\Catalog\Plm\Services;

use App\Foundation\Rules\RuleEngine;
use Modules\Catalog\Network\Models\HomePass;
use Modules\Catalog\Plm\Models\Service;

/**
 * Catalog configuration policy, delegated to the rule engine (FOUNDATION_DROOLS).
 * The design names these packages `rules.service-catalog` and
 * `rules.homepass-catalog`; they validate product/HomePass configuration with
 * stable rule IDs. Results are advisory by default (DROOLS-RES-4: the caller
 * decides blocking vs advisory) and surfaced as policyWarnings.
 */
class CatalogPolicy
{
    public function __construct(private readonly RuleEngine $rules) {}

    /**
     * @return array<int,array<string,mixed>> validation errors (advisory)
     */
    public function validateService(Service $service): array
    {
        return $this->rules->assess('rules.service-catalog', [
            'consumptionModel' => $service->consumption_model,
            'isAddressable' => (bool) $service->is_addressable,
            'equipmentRequirementRef' => $service->equipment_requirement_ref,
            'taxGroupRef' => $service->default_tax_group_ref,
            'walletRef' => $service->default_wallet_ref,
        ])['validationErrors'];
    }

    /**
     * @return array<int,array<string,mixed>> validation errors (advisory)
     */
    public function validateHomePass(HomePass $homepass): array
    {
        return $this->rules->assess('rules.homepass-catalog', [
            'technology' => $homepass->technology,
            'status' => $homepass->status,
            'techRegionId' => $homepass->tech_region_id,
        ])['validationErrors'];
    }
}
