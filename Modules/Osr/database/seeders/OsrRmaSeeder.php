<?php

namespace Modules\Osr\Database\Seeders;

use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Modules\Rules\Models\DecisionTable;
use Modules\Workflow\Models\ProcessDefinition;

/**
 * Seeds OSR-RMA-01 as DATA: the osr-swap process graph + the two Drools packages
 * (swap eligibility, and the recovered-routing rule that codifies the documented
 * fix — recovered units route back to the recovering contractor's warehouse).
 */
class OsrRmaSeeder extends Seeder
{
    public function run(): void
    {
        ProcessDefinition::query()->updateOrCreate(
            ['process_key' => 'osr-swap', 'version' => 1, 'operator_code' => null],
            [
                'definition_id' => Id::make('pdef'),
                'name' => 'OSR-RMA Equipment Swap',
                'graph' => [
                    'nodes' => [
                        ['id' => 'start', 'type' => 'startEvent', 'position' => ['x' => 0, 'y' => 100], 'data' => ['label' => 'Start']],
                        ['id' => 'validate', 'type' => 'serviceTask', 'position' => ['x' => 160, 'y' => 100], 'data' => ['label' => 'Validate eligibility', 'topic' => 'osr.validate-swap-eligibility']],
                        ['id' => 'gw', 'type' => 'exclusiveGateway', 'position' => ['x' => 340, 'y' => 100], 'data' => ['label' => 'Eligible?']],
                        ['id' => 'slot', 'type' => 'serviceTask', 'position' => ['x' => 520, 'y' => 40], 'data' => ['label' => 'Reserve slot', 'topic' => 'osr.reserve-slot']],
                        ['id' => 'wo', 'type' => 'serviceTask', 'position' => ['x' => 700, 'y' => 40], 'data' => ['label' => 'Create WO', 'topic' => 'osr.create-swap-wo']],
                        ['id' => 'await', 'type' => 'userTask', 'position' => ['x' => 880, 'y' => 40], 'data' => ['label' => 'Field visit', 'name' => 'Confirm field visit', 'candidateGroup' => 'FIELD']],
                        ['id' => 'recover', 'type' => 'serviceTask', 'position' => ['x' => 1060, 'y' => 40], 'data' => ['label' => 'Recover source', 'topic' => 'osr.recover-source']],
                        ['id' => 'provision', 'type' => 'serviceTask', 'position' => ['x' => 1240, 'y' => 40], 'data' => ['label' => 'Provision OSS', 'topic' => 'osr.provision-swap']],
                        ['id' => 'complete', 'type' => 'serviceTask', 'position' => ['x' => 1420, 'y' => 40], 'data' => ['label' => 'Complete', 'topic' => 'osr.complete-swap']],
                        ['id' => 'fail', 'type' => 'serviceTask', 'position' => ['x' => 520, 'y' => 180], 'data' => ['label' => 'Fail', 'topic' => 'osr.fail-swap']],
                        ['id' => 'end_ok', 'type' => 'endEvent', 'position' => ['x' => 1600, 'y' => 40], 'data' => ['label' => 'Completed']],
                        ['id' => 'end_failed', 'type' => 'endEvent', 'position' => ['x' => 700, 'y' => 180], 'data' => ['label' => 'Failed']],
                    ],
                    'edges' => [
                        ['id' => 'e1', 'source' => 'start', 'target' => 'validate'],
                        ['id' => 'e2', 'source' => 'validate', 'target' => 'gw'],
                        ['id' => 'e3', 'source' => 'gw', 'target' => 'slot', 'data' => ['condition' => ['var' => 'eligible', 'op' => 'truthy']]],
                        ['id' => 'e4', 'source' => 'gw', 'target' => 'fail', 'data' => ['default' => true]],
                        ['id' => 'e5', 'source' => 'slot', 'target' => 'wo'],
                        ['id' => 'e6', 'source' => 'wo', 'target' => 'await'],
                        ['id' => 'e7', 'source' => 'await', 'target' => 'recover'],
                        ['id' => 'e8', 'source' => 'recover', 'target' => 'provision'],
                        ['id' => 'e9', 'source' => 'provision', 'target' => 'complete'],
                        ['id' => 'e10', 'source' => 'complete', 'target' => 'end_ok'],
                        ['id' => 'e11', 'source' => 'fail', 'target' => 'end_failed'],
                    ],
                ],
                'status' => ProcessDefinition::DEPLOYED,
                'deployed_at' => now(),
            ],
        );

        DecisionTable::query()->updateOrCreate(
            ['rule_set' => 'rules.osr.swap.eligibility', 'version' => 1, 'operator_code' => null],
            [
                'table_id' => Id::make('dt'),
                'name' => 'OSR-RMA swap eligibility',
                'hit_policy' => 'FIRST',
                'inputs' => ['kind', 'sourceState', 'billingState', 'warrantyVoid'],
                'rules' => [
                    ['ruleId' => 'R-OSR-RMA-ELIG-001', 'when' => [['var' => 'billingState', 'op' => 'neq', 'value' => 'GOOD']], 'then' => ['eligible' => false, 'decisionCode' => 'BILLING_BLOCKED']],
                    ['ruleId' => 'R-OSR-RMA-ELIG-002', 'when' => [['var' => 'sourceState', 'op' => 'not_in', 'value' => ['IN_FIELD_ACTIVE', 'IN_FIELD_DEFECTIVE']]], 'then' => ['eligible' => false, 'decisionCode' => 'INVALID_SOURCE_STATE']],
                ],
                'default_output' => ['eligible' => true],
                'status' => DecisionTable::DEPLOYED,
            ],
        );

        // The signature fix: recovered units route back to the recovering contractor.
        DecisionTable::query()->updateOrCreate(
            ['rule_set' => 'rules.osr.recovered-routing', 'version' => 1, 'operator_code' => null],
            [
                'table_id' => Id::make('dt'),
                'name' => 'OSR-RMA recovered-instance routing',
                'hit_policy' => 'FIRST',
                'inputs' => ['recoveryContractorId'],
                'rules' => [],
                'default_output' => ['routeToContractor' => true, 'destination' => 'CONTRACTOR_STOCK'],
                'status' => DecisionTable::DEPLOYED,
            ],
        );
    }
}
