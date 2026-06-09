<?php

namespace Modules\Fulfillment\Database\Seeders;

use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Modules\Workflow\Models\ProcessDefinition;

/**
 * FUL-02 OrderCapture journey AS DATA (FUL-02-FRAMEWORK §1.1: POST /api/orders
 * starts a process instance of this definition). The journey is config: extend or
 * re-sequence it per operator in the Workflow Studio — e.g. insert a credit-check
 * service task or a deposit payment messageCatch — with NO code change, exactly
 * like the subscription MACD flows. Steps are external-task topics registered by
 * FulfillmentWorkflowProvider; install + KYC are message catches correlated by
 * events (WorkOrderFinalized / CustomerKycApproved) or the desk APIs.
 */
class FulfillmentFlowSeeder extends Seeder
{
    public function run(): void
    {
        ProcessDefinition::query()->updateOrCreate(
            ['process_key' => 'ful-order-capture', 'version' => 1, 'operator_code' => null],
            [
                'definition_id' => Id::make('pdef'),
                'name' => 'Fulfillment — Order Capture',
                'graph' => [
                    'nodes' => [
                        ['id' => 'start', 'type' => 'startEvent', 'position' => ['x' => 0, 'y' => 80], 'data' => ['label' => 'Start']],
                        ['id' => 'validate', 'type' => 'serviceTask', 'position' => ['x' => 150, 'y' => 80], 'data' => ['label' => 'Validate order', 'topic' => 'order-validate']],
                        ['id' => 'gw', 'type' => 'exclusiveGateway', 'position' => ['x' => 300, 'y' => 80], 'data' => ['label' => 'Eligible?']],
                        ['id' => 'create_sub', 'type' => 'serviceTask', 'position' => ['x' => 450, 'y' => 20], 'data' => ['label' => 'Create subscription', 'topic' => 'order-create-subscription']],
                        ['id' => 'create_wo', 'type' => 'serviceTask', 'position' => ['x' => 600, 'y' => 20], 'data' => ['label' => 'Create install WO', 'topic' => 'order-create-install-wo']],
                        ['id' => 'await_install', 'type' => 'messageCatch', 'position' => ['x' => 750, 'y' => 20], 'data' => ['label' => 'Await install', 'messageName' => 'ful-install-finalized']],
                        ['id' => 'kyc', 'type' => 'serviceTask', 'position' => ['x' => 900, 'y' => 20], 'data' => ['label' => 'KYC gate', 'topic' => 'order-kyc-gate']],
                        ['id' => 'gw_kyc', 'type' => 'exclusiveGateway', 'position' => ['x' => 1050, 'y' => 20], 'data' => ['label' => 'KYC approved?']],
                        ['id' => 'await_kyc', 'type' => 'messageCatch', 'position' => ['x' => 1050, 'y' => 140], 'data' => ['label' => 'Await KYC approval', 'messageName' => 'ful-kyc-approved']],
                        ['id' => 'activate', 'type' => 'serviceTask', 'position' => ['x' => 1200, 'y' => 20], 'data' => ['label' => 'Trigger activation', 'topic' => 'order-trigger-activation']],
                        ['id' => 'complete', 'type' => 'serviceTask', 'position' => ['x' => 1350, 'y' => 20], 'data' => ['label' => 'Complete order', 'topic' => 'order-complete']],
                        ['id' => 'end_ok', 'type' => 'endEvent', 'position' => ['x' => 1500, 'y' => 20], 'data' => ['label' => 'Completed']],
                        ['id' => 'end_rejected', 'type' => 'endEvent', 'position' => ['x' => 450, 'y' => 160], 'data' => ['label' => 'Rejected']],
                    ],
                    'edges' => [
                        ['id' => 'e1', 'source' => 'start', 'target' => 'validate'],
                        ['id' => 'e2', 'source' => 'validate', 'target' => 'gw'],
                        ['id' => 'e3', 'source' => 'gw', 'target' => 'create_sub', 'data' => ['condition' => ['var' => 'eligible', 'op' => 'truthy']]],
                        ['id' => 'e4', 'source' => 'gw', 'target' => 'end_rejected', 'data' => ['default' => true]],
                        ['id' => 'e5', 'source' => 'create_sub', 'target' => 'create_wo'],
                        ['id' => 'e6', 'source' => 'create_wo', 'target' => 'await_install'],
                        ['id' => 'e7', 'source' => 'await_install', 'target' => 'kyc'],
                        ['id' => 'e8', 'source' => 'kyc', 'target' => 'gw_kyc'],
                        ['id' => 'e9', 'source' => 'gw_kyc', 'target' => 'activate', 'data' => ['condition' => ['var' => 'kycApproved', 'op' => 'truthy']]],
                        ['id' => 'e10', 'source' => 'gw_kyc', 'target' => 'await_kyc', 'data' => ['default' => true]],
                        ['id' => 'e11', 'source' => 'await_kyc', 'target' => 'kyc'], // loop back through the gate after approval
                        ['id' => 'e12', 'source' => 'activate', 'target' => 'complete'],
                        ['id' => 'e13', 'source' => 'complete', 'target' => 'end_ok'],
                    ],
                ],
                'status' => ProcessDefinition::DEPLOYED,
                'deployed_at' => now(),
            ],
        );
    }
}
