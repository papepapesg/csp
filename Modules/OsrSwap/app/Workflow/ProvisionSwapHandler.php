<?php

namespace Modules\Osr\Swap\Workflow;

use Modules\Osr\Models\EquipmentInstance;
use Modules\Osr\Swap\Models\EquipmentSwapRequest;
use Modules\Osr\Services\EquipmentInstanceService;
use Modules\Provisioning\Services\ProvisioningService;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * OSR-RMA-01 OSS provisioning. HFC: Broadhub serial unbind/rebind with slot/port
 * preservation; GPON: NMS ONT unassign/bind. Adapters are operator-configurable
 * stubs in v1.0 (driven through the swappable provisioning adapter). Binds the
 * target instance to the customer when one is placed.
 */
class ProvisionSwapHandler implements TaskHandler
{
    public function __construct(
        private readonly ProvisioningService $provisioning,
        private readonly EquipmentInstanceService $instances,
    ) {}

    public function topic(): string
    {
        return 'osr.provision-swap';
    }

    public function label(): string
    {
        return 'OSR-RMA: Provision swap (OSS)';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $swap = EquipmentSwapRequest::query()->find($context->businessKey());
        if (! $swap) {
            return TaskResult::fail('Swap request not found', retryable: false);
        }

        $target = $swap->kind === 'SWAP_HFC' ? 'CMTS_HFC_KE' : ($swap->kind === 'SWAP_GPON' ? 'HUAWEI_NCE_GPON_KE' : 'DEFAULT_NMS');
        $commands = $this->provisioning->broadcast(
            subscriptionId: (string) $swap->subscription_id,
            action: 'SWAP',
            commands: [[
                'target_code' => $target,
                'desired_state' => ['desiredStatus' => 'ACTIVE', 'swapKind' => $swap->kind],
            ]],
        );

        // Bind the target instance to the customer in the field (if one is placed).
        if ($swap->target_instance_id) {
            $targetInstance = EquipmentInstance::query()->find($swap->target_instance_id);
            if ($targetInstance && in_array($targetInstance->state, [EquipmentInstance::IN_CONTRACTOR_STOCK, EquipmentInstance::RESERVED_FOR_WO], true)) {
                $this->instances->transition($targetInstance, EquipmentInstance::IN_FIELD_ACTIVE, [
                    'customer_id' => $swap->customer_id, 'subscription_id' => $swap->subscription_id, 'reference' => $swap->swap_id,
                ]);
            }
        }

        $confirmed = collect($commands)->every(fn ($c) => $c->status === 'CONFIRMED');

        return $confirmed
            ? TaskResult::success(['provisioned' => true])
            : TaskResult::fail('OSS provisioning rejected', retryable: true);
    }
}
