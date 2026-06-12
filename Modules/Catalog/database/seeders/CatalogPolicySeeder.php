<?php

namespace Modules\Catalog\Database\Seeders;

use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Modules\Rules\Models\DecisionTable;

/**
 * Seeds the catalog configuration rule packages named by the design:
 * `rules.service-catalog` (PLM-CFG-01), `rules.homepass-catalog` (RLM-CFG-01),
 * `rules.campaign.eligibility` + `rules.campaign.validation` (SIP-05). COLLECT hit
 * policy so all ValidationErrors are returned (DROOLS-RES-3); each carries a stable
 * ruleId. Operators override per market in the Rules Studio — these are config, not code.
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

        // SIP-04 §10 bundle-launch-validation.drl — operator-variable launch readiness
        // (e.g. minimum package mix). Fixed ref-existence checks stay in code; these gates
        // are config. The service supplies facts; this table decides what blocks launch.
        DecisionTable::query()->updateOrCreate(
            ['rule_set' => 'rules.bundle.launch-validation', 'version' => 1, 'operator_code' => null],
            [
                'table_id' => Id::make('dt'),
                'name' => 'Bundle launch validation',
                'hit_policy' => 'COLLECT',
                'inputs' => ['mandatoryComponentCount', 'componentCount', 'bundleType', 'hasAvailability'],
                'rules' => [
                    ['ruleId' => 'BUN-VAL-MANDATORY', 'when' => [['var' => 'mandatoryComponentCount', 'op' => 'lt', 'value' => 1]], 'then' => ['error' => ['field' => 'components', 'message' => 'Bundle has no mandatory package component.']]],
                ],
                'default_output' => ['valid' => true],
                'status' => DecisionTable::DEPLOYED,
            ],
        );

        // SIP-05 §10 campaign-eligibility.drl — the operator-variable gates that decide
        // whether a campaign is offerable. The service computes the facts; this table
        // decides. An operator adds/removes a gate by editing this row (Rules Studio),
        // no code change. Each gate emits a ValidationError = an eligibility reason.
        DecisionTable::query()->updateOrCreate(
            ['rule_set' => 'rules.campaign.eligibility', 'version' => 1, 'operator_code' => null],
            [
                'table_id' => Id::make('dt'),
                'name' => 'Campaign eligibility gates',
                'hit_policy' => 'COLLECT',
                'inputs' => ['active', 'withinWindow', 'channelAllowed', 'capReached', 'hardRuleFailed', 'campaignType', 'channelCode'],
                'rules' => [
                    ['ruleId' => 'CAMP-ELIG-ACTIVE', 'when' => [['var' => 'active', 'op' => 'falsy']], 'then' => ['error' => ['field' => 'status', 'message' => 'CAMPAIGN_NOT_ACTIVE']]],
                    ['ruleId' => 'CAMP-ELIG-WINDOW', 'when' => [['var' => 'withinWindow', 'op' => 'falsy']], 'then' => ['error' => ['field' => 'window', 'message' => 'CAMPAIGN_OUT_OF_WINDOW']]],
                    ['ruleId' => 'CAMP-ELIG-CHANNEL', 'when' => [['var' => 'channelAllowed', 'op' => 'falsy']], 'then' => ['error' => ['field' => 'channel', 'message' => 'CHANNEL_NOT_ALLOWED']]],
                    ['ruleId' => 'CAMP-ELIG-CAP', 'when' => [['var' => 'capReached', 'op' => 'truthy']], 'then' => ['error' => ['field' => 'cap', 'message' => 'PARTICIPANT_CAP_REACHED']]],
                    ['ruleId' => 'CAMP-ELIG-TARGET', 'when' => [['var' => 'hardRuleFailed', 'op' => 'truthy']], 'then' => ['error' => ['field' => 'target', 'message' => 'TARGET_RULE_FAILED']]],
                ],
                'default_output' => ['eligible' => true],
                'status' => DecisionTable::DEPLOYED,
            ],
        );

        // SIP-05 §7.4 campaign launch validation — the operator-variable launch checks
        // (R-SIP-CAMP-02/03). Fixed ref-existence checks stay in code; these gates are config.
        DecisionTable::query()->updateOrCreate(
            ['rule_set' => 'rules.campaign.validation', 'version' => 1, 'operator_code' => null],
            [
                'table_id' => Id::make('dt'),
                'name' => 'Campaign launch validation',
                'hit_policy' => 'COLLECT',
                'inputs' => ['hasActiveOffer', 'datesValid', 'campaignType'],
                'rules' => [
                    ['ruleId' => 'CAMP-VAL-OFFER', 'when' => [['var' => 'hasActiveOffer', 'op' => 'falsy']], 'then' => ['error' => ['field' => 'offers', 'message' => 'Campaign has no active offer (use a MESSAGE_ONLY offer for message-only campaigns).']]],
                    ['ruleId' => 'CAMP-VAL-DATES', 'when' => [['var' => 'datesValid', 'op' => 'falsy']], 'then' => ['error' => ['field' => 'dates', 'message' => 'Campaign end date is before its start date.']]],
                ],
                'default_output' => ['valid' => true],
                'status' => DecisionTable::DEPLOYED,
            ],
        );
    }
}
