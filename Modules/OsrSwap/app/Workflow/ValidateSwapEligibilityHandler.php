<?php

namespace Modules\Osr\Swap\Workflow;

use App\Foundation\Rules\RuleEngine;
use Modules\Osr\Models\EquipmentInstance;
use Modules\Osr\Swap\Models\EquipmentSwapRequest;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * OSR-RMA-01 eligibility worker. Validates warranty / billing / source-instance
 * state before any field resource is committed (no slot, no WO, no truck roll on a
 * failed check). Resolves the chargeable flag + charge_code. Runs
 * rules.osr.swap.eligibility.
 */
class ValidateSwapEligibilityHandler implements TaskHandler
{
    public function __construct(private readonly RuleEngine $rules) {}

    public function topic(): string
    {
        return 'osr.validate-swap-eligibility';
    }

    public function label(): string
    {
        return 'OSR-RMA: Validate swap eligibility (rules)';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $swap = EquipmentSwapRequest::query()->find($context->businessKey());
        if (! $swap) {
            return TaskResult::fail('Swap request not found', retryable: false);
        }

        $source = $swap->source_instance_id ? EquipmentInstance::query()->find($swap->source_instance_id) : null;
        $payload = $swap->flow_payload ?? [];

        $facts = [
            'kind' => $swap->kind,
            'sourceState' => $source->state ?? null,
            'billingState' => $payload['billingState'] ?? 'GOOD',
            'warrantyVoid' => (bool) ($payload['warrantyVoid'] ?? false),
        ];

        $result = $this->rules->assess('rules.osr.swap.eligibility', $facts);
        $eligible = $result['decision']['eligible'] ?? true;

        // EQU (equipment upgrade) is always chargeable (upgrade fee); otherwise
        // chargeable only when the warranty is void / customer-caused (BIL-01 owns
        // the code catalog).
        $isUpgrade = $swap->kind === 'EQU';
        $chargeable = $eligible && ($isUpgrade || $facts['warrantyVoid']);
        $swap->update([
            'status' => 'VALIDATING',
            'chargeable' => $chargeable,
            'charge_code' => $chargeable ? ($isUpgrade ? 'UPGRADE_FEE' : 'OUT_OF_WARRANTY') : null,
        ]);

        return TaskResult::success([
            'eligible' => $eligible,
            'eligibilityReason' => $result['decision']['decisionCode'] ?? null,
            'chargeable' => $chargeable,
        ]);
    }
}
