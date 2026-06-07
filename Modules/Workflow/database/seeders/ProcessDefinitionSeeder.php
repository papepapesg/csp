<?php

namespace Modules\Workflow\Database\Seeders;

use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Modules\Workflow\Models\ProcessDefinition;

/**
 * Seeds the default (global) subscription workflow definitions AS DATA. These
 * are the flows the SUB-WF framework starts; an operator can override any of
 * them by deploying an operator-scoped definition with the same process_key —
 * no code change (CAM-BPMN-2). Editable in the Backoffice workflow studio.
 */
class ProcessDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $this->deploy('sub-activate', 'Subscription Activation', [
            'nodes' => [
                ['id' => 'start', 'type' => 'startEvent', 'position' => ['x' => 0, 'y' => 80], 'data' => ['label' => 'Start']],
                ['id' => 'validate', 'type' => 'serviceTask', 'position' => ['x' => 180, 'y' => 80], 'data' => ['label' => 'Validate activation', 'topic' => 'sub.validate-activation']],
                ['id' => 'gw', 'type' => 'exclusiveGateway', 'position' => ['x' => 380, 'y' => 80], 'data' => ['label' => 'Eligible?']],
                ['id' => 'activate', 'type' => 'serviceTask', 'position' => ['x' => 560, 'y' => 20], 'data' => ['label' => 'Set active', 'topic' => 'sub.activate']],
                ['id' => 'notify', 'type' => 'serviceTask', 'position' => ['x' => 760, 'y' => 20], 'data' => ['label' => 'Notify customer', 'topic' => 'notify.send', 'config' => ['channel' => 'SMS', 'template' => 'SUBSCRIPTION_ACTIVATED']]],
                ['id' => 'end_ok', 'type' => 'endEvent', 'position' => ['x' => 960, 'y' => 20], 'data' => ['label' => 'Activated']],
                ['id' => 'end_rejected', 'type' => 'endEvent', 'position' => ['x' => 560, 'y' => 160], 'data' => ['label' => 'Rejected']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'validate'],
                ['id' => 'e2', 'source' => 'validate', 'target' => 'gw'],
                ['id' => 'e3', 'source' => 'gw', 'target' => 'activate', 'data' => ['label' => 'eligible', 'condition' => ['var' => 'eligible', 'op' => 'truthy']]],
                ['id' => 'e4', 'source' => 'gw', 'target' => 'end_rejected', 'data' => ['label' => 'not eligible', 'default' => true]],
                ['id' => 'e5', 'source' => 'activate', 'target' => 'notify'],
                ['id' => 'e6', 'source' => 'notify', 'target' => 'end_ok'],
            ],
        ]);

        $this->deploy('sub-terminate', 'Subscription Termination', [
            'nodes' => [
                ['id' => 'start', 'type' => 'startEvent', 'position' => ['x' => 0, 'y' => 80], 'data' => ['label' => 'Start']],
                ['id' => 'terminate', 'type' => 'serviceTask', 'position' => ['x' => 200, 'y' => 80], 'data' => ['label' => 'Terminate', 'topic' => 'sub.terminate']],
                ['id' => 'end_ok', 'type' => 'endEvent', 'position' => ['x' => 420, 'y' => 80], 'data' => ['label' => 'Terminated']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'terminate'],
                ['id' => 'e2', 'source' => 'terminate', 'target' => 'end_ok'],
            ],
        ]);
    }

    /** @param array<string,mixed> $graph */
    private function deploy(string $key, string $name, array $graph): void
    {
        ProcessDefinition::query()->updateOrCreate(
            ['process_key' => $key, 'version' => 1, 'operator_code' => null],
            [
                'definition_id' => Id::make('pdef'),
                'name' => $name,
                'graph' => $graph,
                'status' => ProcessDefinition::DEPLOYED,
                'deployed_at' => now(),
            ],
        );
    }
}
