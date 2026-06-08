<?php

namespace Modules\Workflow\Database\Seeders;

use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Modules\Workflow\Models\ProcessDefinition;

/**
 * Seeds the default (global) subscription workflow definitions AS DATA. State-
 * affecting operations follow the SUB-WF-FRAMEWORK-01 commit-window sequence:
 *   validate (drools) -> gateway -> enter-pending (PENDING_*) -> fulfillment
 *   (FUL/network) -> commit (final status) -> notify.
 * The transient PENDING_* state is held across the fulfillment call, so the master
 * status reflects "an operation is committing" and the network is gated before the
 * final status lands. An operator overrides any of these by deploying a same-key
 * definition with an operator_code (CAM-BPMN-2). Editable in the studio.
 */
class ProcessDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        // Activation keeps its own shape (created PENDING_ACTIVATION; provision gates).
        $this->deploy('sub-activate', 'Subscription Activation', [
            'nodes' => [
                $this->n('start', 'startEvent', 0, 'Start'),
                $this->svc('validate', 180, 'Validate activation', 'sub.validate-activation'),
                $this->gw('gw', 380),
                $this->svc('provision', 560, 'Provision network', 'provisioning.activate-service', ['target' => 'HUAWEI_NCE_GPON_KE', 'speedProfile' => '100M'], 20),
                $this->svc('activate', 740, 'Commit active', 'sub.activate', [], 20),
                $this->svc('notify', 920, 'Notify', 'notify.send', ['channel' => 'SMS', 'template' => 'SUBSCRIPTION_ACTIVATED'], 20),
                $this->end('end_ok', 1100, 'Activated', 20),
                $this->end('end_rejected', 560, 'Rejected', 160),
            ],
            'edges' => [
                $this->e('e1', 'start', 'validate'),
                $this->e('e2', 'validate', 'gw'),
                $this->cond('e3', 'gw', 'provision', 'eligible'),
                $this->def('e4', 'gw', 'end_rejected'),
                $this->e('e5', 'provision', 'activate'),
                $this->e('e6', 'activate', 'notify'),
                $this->e('e7', 'notify', 'end_ok'),
            ],
        ]);

        // State-affecting operations — the framework commit-window sequence.
        $this->stateOp('sub-pause', 'Subscription Pause', 'sub.validate-operation',
            ['ruleSet' => 'rules.subscription.pause', 'requiredStatus' => 'ACTIVE'],
            'PENDING_PAUSE', 'PAUSE',
            ['action' => 'SUSPEND', 'target' => 'DEFAULT_NMS', 'desiredStatus' => 'SUSPENDED'],
            'sub.pause', [], 'SUBSCRIPTION_PAUSED',
            ['intentType' => 'PAUSE_FEE', 'payFirst' => false, 'amountVar' => 'pauseFee']);

        $this->stateOp('sub-resume', 'Subscription Resume', 'sub.validate-operation',
            ['ruleSet' => 'rules.subscription.resume', 'requiredStatus' => 'SUSPENDED', 'requireOpenPause' => true],
            'PENDING_RESUME', 'RESUME',
            ['action' => 'ACTIVATE', 'target' => 'DEFAULT_NMS', 'desiredStatus' => 'ACTIVE'],
            'sub.resume', [], 'SUBSCRIPTION_RESUMED',
            ['intentType' => 'RECONNECTION_FEE', 'payFirst' => false, 'amountVar' => 'reconnectionFee']);

        $this->stateOp('sub-suspend-np', 'Subscription Suspend (non-payment)', 'sub.validate-operation',
            ['ruleSet' => 'rules.subscription.suspend-np', 'requiredStatus' => 'ACTIVE'],
            'PENDING_SUSPEND_NP', 'SUSPEND_NP',
            ['action' => 'SUSPEND', 'target' => 'DEFAULT_NMS', 'desiredStatus' => 'SUSPENDED'],
            'sub.suspend', [], 'SUBSCRIPTION_SUSPENDED_NP');

        $this->stateOp('sub-upgrade', 'Subscription Upgrade', 'sub.validate-package-change',
            ['ruleSet' => 'rules.subscription.upgrade'],
            'PENDING_UPGRADE', 'UPGRADE',
            ['action' => 'MODIFY', 'target' => 'DEFAULT_NMS', 'desiredStatus' => 'ACTIVE'],
            'sub.change-package', ['transition' => 'UPGRADE', 'event' => 'SubscriptionUpgraded'], 'SUBSCRIPTION_UPGRADED',
            ['intentType' => 'PRORATION', 'payFirst' => true, 'amountVar' => 'priceDelta']);

        $this->stateOp('sub-downgrade', 'Subscription Downgrade', 'sub.validate-package-change',
            ['ruleSet' => 'rules.subscription.downgrade'],
            'PENDING_DOWNGRADE', 'DOWNGRADE',
            ['action' => 'MODIFY', 'target' => 'DEFAULT_NMS', 'desiredStatus' => 'ACTIVE'],
            'sub.change-package', ['transition' => 'DOWNGRADE', 'event' => 'SubscriptionDowngraded'], 'SUBSCRIPTION_DOWNGRADED',
            ['intentType' => 'PRORATION', 'payFirst' => false, 'amountVar' => 'priceDelta']);

        // Relocation: a physical move, so it raises a WO-01 SHIFTING work order
        // (disconnect at source / reconnect at target) before the network call.
        $this->stateOp('sub-relocation', 'Subscription Relocation', 'sub.validate-homepass-change',
            ['ruleSet' => 'rules.subscription.relocation'],
            'PENDING_RELOCATION', 'RELOCATION',
            ['action' => 'MODIFY', 'target' => 'DEFAULT_NMS', 'desiredStatus' => 'ACTIVE'],
            'sub.change-homepass', ['transition' => 'RELOCATION', 'event' => 'SubscriptionRelocated'], 'SUBSCRIPTION_RELOCATED',
            null,
            ['id' => 'shifting_wo', 'topic' => 'sub.create-shifting-wo', 'label' => 'Create SHIFTING work order']);

        $this->stateOp('sub-migration', 'Subscription Migration', 'sub.validate-homepass-change',
            ['ruleSet' => 'rules.subscription.migration'],
            'PENDING_MIGRATION', 'MIGRATION',
            ['action' => 'MODIFY', 'target' => 'DEFAULT_NMS', 'desiredStatus' => 'ACTIVE'],
            'sub.change-homepass', ['transition' => 'MIGRATION', 'event' => 'SubscriptionMigrated'], 'SUBSCRIPTION_MIGRATED');

        // Terminate: ACTIVE -> PENDING_TERMINATION -> deprovision -> TERMINATED ->
        // equipment pickup (OSR-RMA EQP) -> notify. The pickup is raised after the
        // master is terminated so each field-active device is recovered from the site.
        $this->deploy('sub-terminate', 'Subscription Termination', [
            'nodes' => [
                $this->n('start', 'startEvent', 0, 'Start'),
                $this->svc('enter', 180, 'Enter pending termination', 'sub.put-pending-status', ['pendingStatus' => 'PENDING_TERMINATION', 'transitionType' => 'TERMINATE']),
                $this->svc('fulfil', 360, 'Deprovision network', 'sub.fulfillment-call', ['action' => 'DEACTIVATE', 'target' => 'DEFAULT_NMS', 'desiredStatus' => 'NOT_PRESENT']),
                $this->svc('terminate', 540, 'Commit terminated', 'sub.terminate'),
                $this->svc('pickup', 720, 'Trigger equipment pickup (EQP)', 'sub.trigger-equipment-pickup', [], 20),
                $this->svc('notify', 900, 'Notify', 'notify.send', ['channel' => 'SMS', 'template' => 'SUBSCRIPTION_TERMINATED']),
                $this->end('end_ok', 1080, 'Terminated'),
            ],
            'edges' => [
                $this->e('e1', 'start', 'enter'),
                $this->e('e2', 'enter', 'fulfil'),
                $this->e('e3', 'fulfil', 'terminate'),
                $this->e('e4', 'terminate', 'pickup'),
                $this->e('e5', 'pickup', 'notify'),
                $this->e('e6', 'notify', 'end_ok'),
            ],
        ]);

        // SUB-WF-RESTRICT-01: the one operation with NO transient (stays ACTIVE).
        $this->deploy('sub-restrict', 'Subscription Restriction', [
            'nodes' => [
                $this->n('start', 'startEvent', 0, 'Start'),
                $this->svc('validate', 180, 'Validate restriction (rules)', 'sub.validate-operation', ['ruleSet' => 'rules.subscription.restrict', 'requiredStatus' => 'ACTIVE']),
                $this->gw('gw', 380),
                $this->svc('mutate', 560, 'Put active restrictions', 'sub.put-active-restrictions', [], 20),
                $this->svc('notify', 740, 'Notify', 'notify.send', ['channel' => 'SMS', 'template' => 'SUBSCRIPTION_RESTRICTION_CHANGED'], 20),
                $this->end('end_ok', 920, 'Applied', 20),
                $this->end('end_rejected', 560, 'Rejected', 160),
            ],
            'edges' => [
                $this->e('e1', 'start', 'validate'),
                $this->e('e2', 'validate', 'gw'),
                $this->cond('e3', 'gw', 'mutate', 'eligible'),
                $this->def('e4', 'gw', 'end_rejected'),
                $this->e('e5', 'mutate', 'notify'),
                $this->e('e6', 'notify', 'end_ok'),
            ],
        ]);
    }

    /**
     * Build a standard state-affecting flow: validate -> gw -> enter-pending ->
     * [billing] -> [beforeFulfil] -> fulfillment -> commit -> notify.
     *
     * @param  array{id:string,topic:string,config?:array,label?:string}|null  $beforeFulfil
     *   Optional cross-module service step inserted immediately before the network
     *   fulfillment (e.g. relocation raising a WO-01 SHIFTING work order).
     */
    private function stateOp(string $key, string $name, string $validateTopic, array $validateCfg, string $pendingStatus, string $transitionType, array $fulfillment, string $commitTopic, array $commitCfg, string $notifyTemplate, ?array $billing = null, ?array $beforeFulfil = null): void
    {
        // Without billing: validate -> gw -> enter -> fulfil -> commit -> notify.
        // With billing: enter -> billing-intent -> gw_pay -> [await-payment] -> fulfil -> ...
        $nodes = [
            $this->n('start', 'startEvent', 0, 'Start'),
            $this->svc('validate', 140, 'Validate', $validateTopic, $validateCfg),
            $this->gw('gw', 300),
            $this->svc('enter', 460, 'Enter pending status', 'sub.put-pending-status', ['pendingStatus' => $pendingStatus, 'transitionType' => $transitionType], 20),
            $this->svc('fulfil', 1020, 'Fulfillment call (network)', 'sub.fulfillment-call', $fulfillment, 20),
            $this->svc('commit', 1180, 'Commit final state', $commitTopic, $commitCfg, 20),
            $this->svc('notify', 1340, 'Notify', 'notify.send', ['channel' => 'SMS', 'template' => $notifyTemplate], 20),
            $this->end('end_ok', 1500, 'Committed', 20),
            $this->end('end_rejected', 460, 'Rejected', 160),
        ];
        $edges = [
            $this->e('e1', 'start', 'validate'),
            $this->e('e2', 'validate', 'gw'),
            $this->cond('e3', 'gw', 'enter', 'eligible'),
            $this->def('e4', 'gw', 'end_rejected'),
            $this->e('e7', 'commit', 'notify'),
            $this->e('e8', 'notify', 'end_ok'),
        ];

        if ($billing) {
            // enter -> billing-intent -> gw_pay -> (await-payment ->) fulfil
            $nodes[] = $this->svc('billing', 620, 'Billing intent (BIL-01)', 'sub.billing-intent', $billing, 20);
            $nodes[] = $this->gw('gw_pay', 760);
            $nodes[] = ['id' => 'await_pay', 'type' => 'messageCatch', 'position' => ['x' => 880, 'y' => 110], 'data' => ['label' => 'Await payment', 'messageName' => 'sub-payment-confirmed']];
            $edges[] = $this->e('eb1', 'enter', 'billing');
            $edges[] = $this->e('eb2', 'billing', 'gw_pay');
            $edges[] = $this->cond('eb3', 'gw_pay', 'await_pay', 'paymentRequired');
            $edges[] = $this->def('eb4', 'gw_pay', 'fulfil');
            $edges[] = $this->e('eb5', 'await_pay', 'fulfil');
        } else {
            $edges[] = $this->e('e5', 'enter', 'fulfil');
        }
        $edges[] = $this->e('e6', 'fulfil', 'commit');

        // Inject an optional cross-module step just before fulfil: retarget every
        // edge currently pointing at 'fulfil' to the new node, then chain it on.
        if ($beforeFulfil) {
            $nodes[] = $this->svc($beforeFulfil['id'], 940, $beforeFulfil['label'] ?? 'Pre-fulfillment step', $beforeFulfil['topic'], $beforeFulfil['config'] ?? [], 20);
            foreach ($edges as $i => $edge) {
                if (($edge['target'] ?? null) === 'fulfil') {
                    $edges[$i]['target'] = $beforeFulfil['id'];
                }
            }
            $edges[] = $this->e('ebf', $beforeFulfil['id'], 'fulfil');
        }

        $this->deploy($key, $name, ['nodes' => $nodes, 'edges' => $edges]);
    }

    // ---- graph node/edge builders ----
    private function n(string $id, string $type, int $x, string $label, int $y = 80): array
    {
        return ['id' => $id, 'type' => $type, 'position' => ['x' => $x, 'y' => $y], 'data' => ['label' => $label]];
    }

    private function svc(string $id, int $x, string $label, string $topic, array $config = [], int $y = 80): array
    {
        $data = ['label' => $label, 'topic' => $topic];
        if ($config) {
            $data['config'] = $config;
        }

        return ['id' => $id, 'type' => 'serviceTask', 'position' => ['x' => $x, 'y' => $y], 'data' => $data];
    }

    private function gw(string $id, int $x): array
    {
        return ['id' => $id, 'type' => 'exclusiveGateway', 'position' => ['x' => $x, 'y' => 80], 'data' => ['label' => 'Eligible?']];
    }

    private function end(string $id, int $x, string $label, int $y = 80): array
    {
        return ['id' => $id, 'type' => 'endEvent', 'position' => ['x' => $x, 'y' => $y], 'data' => ['label' => $label]];
    }

    private function e(string $id, string $from, string $to): array
    {
        return ['id' => $id, 'source' => $from, 'target' => $to];
    }

    private function cond(string $id, string $from, string $to, string $var): array
    {
        return ['id' => $id, 'source' => $from, 'target' => $to, 'data' => ['condition' => ['var' => $var, 'op' => 'truthy']]];
    }

    private function def(string $id, string $from, string $to): array
    {
        return ['id' => $id, 'source' => $from, 'target' => $to, 'data' => ['default' => true]];
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
