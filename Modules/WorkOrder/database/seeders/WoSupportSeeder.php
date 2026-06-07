<?php

namespace Modules\WorkOrder\Database\Seeders;

use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Modules\Rules\Models\DecisionTable;
use Modules\Workflow\Models\ProcessDefinition;
use Modules\WorkOrder\Models\WoFlowConfig;
use Modules\WorkOrder\Models\WoJobTypeCatalog;

/**
 * Seeds the WO-01-FLOW-SUPPORT capability as DATA: the wo-support process graph,
 * the per-operator job-type catalog + flow config, and the two Drools packages
 * (site-visit-decision, resolution-gate). A new market is config rows, not code.
 */
class WoSupportSeeder extends Seeder
{
    public function run(): void
    {
        $operator = config('sophix.default_operator', 'WIK');

        $this->deployFlow();
        $this->seedJobTypes($operator);
        $this->seedFlowConfig($operator);
        $this->seedRules();
    }

    private function deployFlow(): void
    {
        ProcessDefinition::query()->updateOrCreate(
            ['process_key' => 'wo-support', 'version' => 1, 'operator_code' => null],
            [
                'definition_id' => Id::make('pdef'),
                'name' => 'Work Order — Support',
                'graph' => [
                    'nodes' => [
                        ['id' => 'start', 'type' => 'startEvent', 'position' => ['x' => 0, 'y' => 100], 'data' => ['label' => 'Start']],
                        ['id' => 'warranty', 'type' => 'serviceTask', 'position' => ['x' => 160, 'y' => 100], 'data' => ['label' => 'Check warranty linkage', 'topic' => 'wo.check-warranty']],
                        ['id' => 'sitevisit', 'type' => 'serviceTask', 'position' => ['x' => 340, 'y' => 100], 'data' => ['label' => 'Site-visit decision', 'topic' => 'wo.site-visit-decision']],
                        ['id' => 'gw_sv', 'type' => 'exclusiveGateway', 'position' => ['x' => 520, 'y' => 100], 'data' => ['label' => 'Site visit?']],
                        ['id' => 'await', 'type' => 'userTask', 'position' => ['x' => 700, 'y' => 100], 'data' => ['label' => 'Await resolution', 'name' => 'Capture resolution', 'candidateGroup' => 'FIELD']],
                        ['id' => 'resgate', 'type' => 'serviceTask', 'position' => ['x' => 880, 'y' => 100], 'data' => ['label' => 'Resolution gate', 'topic' => 'wo.resolution-gate']],
                        ['id' => 'gw_res', 'type' => 'exclusiveGateway', 'position' => ['x' => 1060, 'y' => 100], 'data' => ['label' => 'Outcome?']],
                        ['id' => 'bindings', 'type' => 'serviceTask', 'position' => ['x' => 1240, 'y' => 20], 'data' => ['label' => 'Capture bindings', 'topic' => 'wo.capture-bindings']],
                        ['id' => 'escalate', 'type' => 'serviceTask', 'position' => ['x' => 1240, 'y' => 180], 'data' => ['label' => 'Mark escalation + QCS', 'topic' => 'wo.mark-escalation']],
                        ['id' => 'finalize', 'type' => 'serviceTask', 'position' => ['x' => 1440, 'y' => 100], 'data' => ['label' => 'Finalize support', 'topic' => 'wo.finalize-support']],
                        ['id' => 'end', 'type' => 'endEvent', 'position' => ['x' => 1620, 'y' => 100], 'data' => ['label' => 'Completed']],
                    ],
                    'edges' => [
                        ['id' => 'e1', 'source' => 'start', 'target' => 'warranty'],
                        ['id' => 'e2', 'source' => 'warranty', 'target' => 'sitevisit'],
                        ['id' => 'e3', 'source' => 'sitevisit', 'target' => 'gw_sv'],
                        ['id' => 'e4', 'source' => 'gw_sv', 'target' => 'await', 'data' => ['label' => 'requires visit', 'condition' => ['var' => 'siteVisitDecision', 'op' => 'eq', 'value' => 'REQUIRES_VISIT']]],
                        ['id' => 'e5', 'source' => 'gw_sv', 'target' => 'await', 'data' => ['label' => 'no site visit', 'default' => true]],
                        ['id' => 'e6', 'source' => 'await', 'target' => 'resgate'],
                        ['id' => 'e7', 'source' => 'resgate', 'target' => 'gw_res'],
                        ['id' => 'e8', 'source' => 'gw_res', 'target' => 'bindings', 'data' => ['label' => 'resolved', 'condition' => ['var' => 'resolutionDecision', 'op' => 'eq', 'value' => 'RESOLVED']]],
                        ['id' => 'e9', 'source' => 'gw_res', 'target' => 'escalate', 'data' => ['label' => 'escalate', 'condition' => ['var' => 'resolutionDecision', 'op' => 'eq', 'value' => 'NOT_RESOLVED_ESCALATE']]],
                        ['id' => 'e10', 'source' => 'gw_res', 'target' => 'finalize', 'data' => ['label' => 'area outage', 'default' => true]],
                        ['id' => 'e11', 'source' => 'bindings', 'target' => 'finalize'],
                        ['id' => 'e12', 'source' => 'escalate', 'target' => 'finalize'],
                        ['id' => 'e13', 'source' => 'finalize', 'target' => 'end'],
                    ],
                ],
                'status' => ProcessDefinition::DEPLOYED,
                'deployed_at' => now(),
            ],
        );
    }

    private function seedJobTypes(string $operator): void
    {
        // job_type_code, kind, display_name, network_type, requires_site_visit, warranty_days
        $rows = [
            ['GP3', 'SUPPORT', 'GPON Internet Service Call', 'GPON', true, 90],
            ['GP4', 'SUPPORT', 'GPON TV Service Call', 'GPON', true, 90],
            ['HS3', 'SUPPORT', 'HFC RF / Signal Level Support', 'HFC', true, 90],
            ['HS4', 'SUPPORT', 'HFC Modem Disconnection', 'HFC', false, 90],
            ['PSO', 'SUPPORT', 'Proactive Support (remote)', null, false, 90],
            ['PSI', 'SUPPORT', 'Proactive Support (inbound)', null, false, 90],
            ['QCS', 'SUPPORT', 'Quality Control Service (NOC)', null, true, 90],
            ['RPT', 'SUPPORT', 'Repeat Work Order', null, false, 90],
        ];
        foreach ($rows as [$code, $kind, $name, $net, $visit, $warranty]) {
            WoJobTypeCatalog::query()->updateOrCreate(
                ['operator_code' => $operator, 'job_type_code' => $code],
                [
                    'id' => Id::make('wojt'),
                    'kind' => $kind,
                    'display_name' => $name,
                    'network_type' => $net,
                    'requires_site_visit' => $visit,
                    'warranty_days' => $warranty,
                ],
            );
        }
    }

    private function seedFlowConfig(string $operator): void
    {
        WoFlowConfig::query()->updateOrCreate(
            ['operator_code' => $operator, 'kind' => 'SUPPORT'],
            ['id' => Id::make('woflow'), 'bpmn_process_key' => 'wo-support', 'drools_kjar_ref' => 'wo-support-wik-v1.0.kjar'],
        );
    }

    private function seedRules(): void
    {
        DecisionTable::query()->updateOrCreate(
            ['rule_set' => 'rules.workorder.site-visit-decision', 'version' => 1, 'operator_code' => null],
            [
                'table_id' => Id::make('dt'),
                'name' => 'WO site-visit decision',
                'hit_policy' => 'FIRST',
                'inputs' => ['requiresSiteVisit', 'jobTypeCode'],
                'rules' => [
                    ['ruleId' => 'R-WO-SUP-SV-001', 'when' => [['var' => 'requiresSiteVisit', 'op' => 'falsy']], 'then' => ['decision' => 'NO_SITE_VISIT']],
                ],
                'default_output' => ['decision' => 'REQUIRES_VISIT'],
                'status' => DecisionTable::DEPLOYED,
            ],
        );

        DecisionTable::query()->updateOrCreate(
            ['rule_set' => 'rules.workorder.resolution-gate', 'version' => 1, 'operator_code' => null],
            [
                'table_id' => Id::make('dt'),
                'name' => 'WO resolution gate',
                'hit_policy' => 'FIRST',
                'inputs' => ['finalReason'],
                'rules' => [
                    ['ruleId' => 'R-WO-SUP-RG-001', 'when' => [['var' => 'finalReason', 'op' => 'eq', 'value' => 'AREA_OUTAGE_OPEN']], 'then' => ['decision' => 'AREA_OUTAGE']],
                    ['ruleId' => 'R-WO-SUP-RG-002', 'when' => [['var' => 'finalReason', 'op' => 'eq', 'value' => 'COULD_NOT_RESOLVE_ESCALATED']], 'then' => ['decision' => 'NOT_RESOLVED_ESCALATE']],
                ],
                'default_output' => ['decision' => 'RESOLVED'],
                'status' => DecisionTable::DEPLOYED,
            ],
        );
    }
}
