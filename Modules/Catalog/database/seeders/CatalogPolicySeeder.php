<?php

namespace Modules\Catalog\Database\Seeders;

use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Modules\Rules\Models\DecisionTable;

/**
 * Seeds the catalog configuration rule packages named by the design:
 * `rules.service-catalog` (PLM-CFG-01) and `rules.homepass-catalog` (RLM-CFG-01).
 * COLLECT hit policy so all ValidationErrors are returned (DROOLS-RES-3); each
 * carries a stable ruleId. Operators override per market in the Rules Studio.
 */
class CatalogPolicySeeder extends Seeder
{
    public function run(): void
    {
        DecisionTable::query()->updateOrCreate(
            ['rule_set' => 'rules.service-catalog', 'version' => 1, 'operator_code' => null],
            [
                'table_id' => Id::make('dt'),
                'name' => 'Service catalog config policy',
                'hit_policy' => 'COLLECT',
                'inputs' => ['consumptionModel', 'isAddressable', 'equipmentRequirementRef', 'taxGroupRef'],
                'rules' => [
                    ['ruleId' => 'R-PLM-SVC-001',
                        'when' => [['var' => 'isAddressable', 'op' => 'truthy'], ['var' => 'equipmentRequirementRef', 'op' => 'falsy']],
                        'then' => ['error' => ['field' => 'equipment_requirement_ref', 'message' => 'An addressable service must declare an equipment requirement']]],
                ],
                'default_output' => ['valid' => true],
                'status' => DecisionTable::DEPLOYED,
            ],
        );

        DecisionTable::query()->updateOrCreate(
            ['rule_set' => 'rules.homepass-catalog', 'version' => 1, 'operator_code' => null],
            [
                'table_id' => Id::make('dt'),
                'name' => 'HomePass catalog config policy',
                'hit_policy' => 'COLLECT',
                'inputs' => ['technology', 'status', 'techRegionId'],
                'rules' => [
                    ['ruleId' => 'R-RLM-HP-001',
                        'when' => [['var' => 'technology', 'op' => 'truthy'], ['var' => 'technology', 'op' => 'not_in', 'value' => ['GPON', 'HFC', 'DOCSIS']]],
                        'then' => ['error' => ['field' => 'technology', 'message' => 'Unsupported access technology']]],
                ],
                'default_output' => ['valid' => true],
                'status' => DecisionTable::DEPLOYED,
            ],
        );
    }
}
