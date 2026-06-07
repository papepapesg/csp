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
                ['id' => 'provision', 'type' => 'serviceTask', 'position' => ['x' => 560, 'y' => 20], 'data' => ['label' => 'Provision network', 'topic' => 'provisioning.activate-service', 'config' => ['target' => 'HUAWEI_NCE_GPON_KE', 'speedProfile' => '100M']]],
                ['id' => 'activate', 'type' => 'serviceTask', 'position' => ['x' => 740, 'y' => 20], 'data' => ['label' => 'Set active', 'topic' => 'sub.activate']],
                ['id' => 'notify', 'type' => 'serviceTask', 'position' => ['x' => 920, 'y' => 20], 'data' => ['label' => 'Notify customer', 'topic' => 'notify.send', 'config' => ['channel' => 'SMS', 'template' => 'SUBSCRIPTION_ACTIVATED']]],
                ['id' => 'end_ok', 'type' => 'endEvent', 'position' => ['x' => 1100, 'y' => 20], 'data' => ['label' => 'Activated']],
                ['id' => 'end_rejected', 'type' => 'endEvent', 'position' => ['x' => 560, 'y' => 160], 'data' => ['label' => 'Rejected']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'validate'],
                ['id' => 'e2', 'source' => 'validate', 'target' => 'gw'],
                ['id' => 'e3', 'source' => 'gw', 'target' => 'provision', 'data' => ['label' => 'eligible', 'condition' => ['var' => 'eligible', 'op' => 'truthy']]],
                ['id' => 'e4', 'source' => 'gw', 'target' => 'end_rejected', 'data' => ['label' => 'not eligible', 'default' => true]],
                ['id' => 'e5', 'source' => 'provision', 'target' => 'activate'],
                ['id' => 'e6', 'source' => 'activate', 'target' => 'notify'],
                ['id' => 'e7', 'source' => 'notify', 'target' => 'end_ok'],
            ],
        ]);

        $this->deploy('sub-pause', 'Subscription Pause', [
            'nodes' => [
                ['id' => 'start', 'type' => 'startEvent', 'position' => ['x' => 0, 'y' => 80], 'data' => ['label' => 'Start']],
                ['id' => 'validate', 'type' => 'serviceTask', 'position' => ['x' => 180, 'y' => 80], 'data' => ['label' => 'Validate pause', 'topic' => 'sub.validate-operation', 'config' => ['ruleSet' => 'rules.subscription.pause', 'requiredStatus' => 'ACTIVE']]],
                ['id' => 'gw', 'type' => 'exclusiveGateway', 'position' => ['x' => 380, 'y' => 80], 'data' => ['label' => 'Eligible?']],
                ['id' => 'pause', 'type' => 'serviceTask', 'position' => ['x' => 560, 'y' => 20], 'data' => ['label' => 'Set paused', 'topic' => 'sub.pause']],
                ['id' => 'notify', 'type' => 'serviceTask', 'position' => ['x' => 740, 'y' => 20], 'data' => ['label' => 'Notify', 'topic' => 'notify.send', 'config' => ['channel' => 'SMS', 'template' => 'SUBSCRIPTION_PAUSED']]],
                ['id' => 'end_ok', 'type' => 'endEvent', 'position' => ['x' => 920, 'y' => 20], 'data' => ['label' => 'Paused']],
                ['id' => 'end_rejected', 'type' => 'endEvent', 'position' => ['x' => 560, 'y' => 160], 'data' => ['label' => 'Rejected']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'validate'],
                ['id' => 'e2', 'source' => 'validate', 'target' => 'gw'],
                ['id' => 'e3', 'source' => 'gw', 'target' => 'pause', 'data' => ['condition' => ['var' => 'eligible', 'op' => 'truthy']]],
                ['id' => 'e4', 'source' => 'gw', 'target' => 'end_rejected', 'data' => ['default' => true]],
                ['id' => 'e5', 'source' => 'pause', 'target' => 'notify'],
                ['id' => 'e6', 'source' => 'notify', 'target' => 'end_ok'],
            ],
        ]);

        $this->deploy('sub-resume', 'Subscription Resume', [
            'nodes' => [
                ['id' => 'start', 'type' => 'startEvent', 'position' => ['x' => 0, 'y' => 80], 'data' => ['label' => 'Start']],
                ['id' => 'validate', 'type' => 'serviceTask', 'position' => ['x' => 180, 'y' => 80], 'data' => ['label' => 'Validate resume', 'topic' => 'sub.validate-operation', 'config' => ['ruleSet' => 'rules.subscription.resume', 'requiredStatus' => 'PAUSED']]],
                ['id' => 'gw', 'type' => 'exclusiveGateway', 'position' => ['x' => 380, 'y' => 80], 'data' => ['label' => 'Eligible?']],
                ['id' => 'resume', 'type' => 'serviceTask', 'position' => ['x' => 560, 'y' => 20], 'data' => ['label' => 'Set active', 'topic' => 'sub.resume']],
                ['id' => 'notify', 'type' => 'serviceTask', 'position' => ['x' => 740, 'y' => 20], 'data' => ['label' => 'Notify', 'topic' => 'notify.send', 'config' => ['channel' => 'SMS', 'template' => 'SUBSCRIPTION_RESUMED']]],
                ['id' => 'end_ok', 'type' => 'endEvent', 'position' => ['x' => 920, 'y' => 20], 'data' => ['label' => 'Resumed']],
                ['id' => 'end_rejected', 'type' => 'endEvent', 'position' => ['x' => 560, 'y' => 160], 'data' => ['label' => 'Rejected']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'validate'],
                ['id' => 'e2', 'source' => 'validate', 'target' => 'gw'],
                ['id' => 'e3', 'source' => 'gw', 'target' => 'resume', 'data' => ['condition' => ['var' => 'eligible', 'op' => 'truthy']]],
                ['id' => 'e4', 'source' => 'gw', 'target' => 'end_rejected', 'data' => ['default' => true]],
                ['id' => 'e5', 'source' => 'resume', 'target' => 'notify'],
                ['id' => 'e6', 'source' => 'notify', 'target' => 'end_ok'],
            ],
        ]);

        $this->deploy('sub-suspend', 'Subscription Suspend (non-payment)', [
            'nodes' => [
                ['id' => 'start', 'type' => 'startEvent', 'position' => ['x' => 0, 'y' => 80], 'data' => ['label' => 'Start']],
                ['id' => 'validate', 'type' => 'serviceTask', 'position' => ['x' => 180, 'y' => 80], 'data' => ['label' => 'Validate suspend', 'topic' => 'sub.validate-operation', 'config' => ['ruleSet' => 'rules.subscription.suspend-np', 'requiredStatus' => 'ACTIVE']]],
                ['id' => 'gw', 'type' => 'exclusiveGateway', 'position' => ['x' => 380, 'y' => 80], 'data' => ['label' => 'Eligible?']],
                ['id' => 'suspend', 'type' => 'serviceTask', 'position' => ['x' => 560, 'y' => 20], 'data' => ['label' => 'Set suspended', 'topic' => 'sub.suspend']],
                ['id' => 'notify', 'type' => 'serviceTask', 'position' => ['x' => 740, 'y' => 20], 'data' => ['label' => 'Notify', 'topic' => 'notify.send', 'config' => ['channel' => 'SMS', 'template' => 'SUBSCRIPTION_SUSPENDED_NP']]],
                ['id' => 'end_ok', 'type' => 'endEvent', 'position' => ['x' => 920, 'y' => 20], 'data' => ['label' => 'Suspended']],
                ['id' => 'end_rejected', 'type' => 'endEvent', 'position' => ['x' => 560, 'y' => 160], 'data' => ['label' => 'Rejected']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'validate'],
                ['id' => 'e2', 'source' => 'validate', 'target' => 'gw'],
                ['id' => 'e3', 'source' => 'gw', 'target' => 'suspend', 'data' => ['condition' => ['var' => 'eligible', 'op' => 'truthy']]],
                ['id' => 'e4', 'source' => 'gw', 'target' => 'end_rejected', 'data' => ['default' => true]],
                ['id' => 'e5', 'source' => 'suspend', 'target' => 'notify'],
                ['id' => 'e6', 'source' => 'notify', 'target' => 'end_ok'],
            ],
        ]);

        // SUB-WF-RESTRICT-01: shared ADD/REMOVE shape; intent carried as a process
        // variable. Validate (rules.subscription.restrict) -> put-active-restrictions
        // (FUL-04 enforcement via emitted event) -> notify. Does NOT touch status.
        $this->deploy('sub-restrict', 'Subscription Restriction', [
            'nodes' => [
                ['id' => 'start', 'type' => 'startEvent', 'position' => ['x' => 0, 'y' => 80], 'data' => ['label' => 'Start']],
                ['id' => 'validate', 'type' => 'serviceTask', 'position' => ['x' => 180, 'y' => 80], 'data' => ['label' => 'Validate restriction (rules)', 'topic' => 'sub.validate-operation', 'config' => ['ruleSet' => 'rules.subscription.restrict', 'requiredStatus' => 'ACTIVE']]],
                ['id' => 'gw', 'type' => 'exclusiveGateway', 'position' => ['x' => 380, 'y' => 80], 'data' => ['label' => 'Eligible?']],
                ['id' => 'mutate', 'type' => 'serviceTask', 'position' => ['x' => 560, 'y' => 20], 'data' => ['label' => 'Put active restrictions', 'topic' => 'sub.put-active-restrictions']],
                ['id' => 'notify', 'type' => 'serviceTask', 'position' => ['x' => 740, 'y' => 20], 'data' => ['label' => 'Notify', 'topic' => 'notify.send', 'config' => ['channel' => 'SMS', 'template' => 'SUBSCRIPTION_RESTRICTION_CHANGED']]],
                ['id' => 'end_ok', 'type' => 'endEvent', 'position' => ['x' => 920, 'y' => 20], 'data' => ['label' => 'Applied']],
                ['id' => 'end_rejected', 'type' => 'endEvent', 'position' => ['x' => 560, 'y' => 160], 'data' => ['label' => 'Rejected']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'validate'],
                ['id' => 'e2', 'source' => 'validate', 'target' => 'gw'],
                ['id' => 'e3', 'source' => 'gw', 'target' => 'mutate', 'data' => ['condition' => ['var' => 'eligible', 'op' => 'truthy']]],
                ['id' => 'e4', 'source' => 'gw', 'target' => 'end_rejected', 'data' => ['default' => true]],
                ['id' => 'e5', 'source' => 'mutate', 'target' => 'notify'],
                ['id' => 'e6', 'source' => 'notify', 'target' => 'end_ok'],
            ],
        ]);

        // SUB-WF-UPGRADE-01 / DOWNGRADE-01: validate target package (rules) ->
        // gateway -> commit package change -> notify. IMMEDIATE effective timing.
        foreach ([
            ['sub-upgrade', 'Subscription Upgrade', 'rules.subscription.upgrade', 'UPGRADE', 'SubscriptionUpgraded', 'SUBSCRIPTION_UPGRADED'],
            ['sub-downgrade', 'Subscription Downgrade', 'rules.subscription.downgrade', 'DOWNGRADE', 'SubscriptionDowngraded', 'SUBSCRIPTION_DOWNGRADED'],
        ] as [$key, $name, $ruleSet, $transition, $event, $template]) {
            $this->deploy($key, $name, [
                'nodes' => [
                    ['id' => 'start', 'type' => 'startEvent', 'position' => ['x' => 0, 'y' => 80], 'data' => ['label' => 'Start']],
                    ['id' => 'validate', 'type' => 'serviceTask', 'position' => ['x' => 180, 'y' => 80], 'data' => ['label' => 'Validate target package', 'topic' => 'sub.validate-package-change', 'config' => ['ruleSet' => $ruleSet]]],
                    ['id' => 'gw', 'type' => 'exclusiveGateway', 'position' => ['x' => 380, 'y' => 80], 'data' => ['label' => 'Eligible?']],
                    ['id' => 'commit', 'type' => 'serviceTask', 'position' => ['x' => 560, 'y' => 20], 'data' => ['label' => 'Commit package change', 'topic' => 'sub.change-package', 'config' => ['transition' => $transition, 'event' => $event]]],
                    ['id' => 'notify', 'type' => 'serviceTask', 'position' => ['x' => 740, 'y' => 20], 'data' => ['label' => 'Notify', 'topic' => 'notify.send', 'config' => ['channel' => 'SMS', 'template' => $template]]],
                    ['id' => 'end_ok', 'type' => 'endEvent', 'position' => ['x' => 920, 'y' => 20], 'data' => ['label' => 'Committed']],
                    ['id' => 'end_rejected', 'type' => 'endEvent', 'position' => ['x' => 560, 'y' => 160], 'data' => ['label' => 'Rejected']],
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'start', 'target' => 'validate'],
                    ['id' => 'e2', 'source' => 'validate', 'target' => 'gw'],
                    ['id' => 'e3', 'source' => 'gw', 'target' => 'commit', 'data' => ['condition' => ['var' => 'eligible', 'op' => 'truthy']]],
                    ['id' => 'e4', 'source' => 'gw', 'target' => 'end_rejected', 'data' => ['default' => true]],
                    ['id' => 'e5', 'source' => 'commit', 'target' => 'notify'],
                    ['id' => 'e6', 'source' => 'notify', 'target' => 'end_ok'],
                ],
            ]);
        }

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
