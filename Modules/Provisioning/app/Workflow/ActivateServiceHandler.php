<?php

namespace Modules\Provisioning\Workflow;

use Modules\Provisioning\Services\ProvisioningService;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * Toolbox step: send the network activation command(s) for a subscription
 * (FUL-03 / PROV-INT-01). Target + profile come from node config; in a real
 * deployment they would be resolved from the HomePass/service profile. Fails the
 * task (and thus the flow) if the NMS rejects, so activation never marks ACTIVE
 * on a network that did not provision.
 */
class ActivateServiceHandler implements TaskHandler
{
    public function __construct(private readonly ProvisioningService $provisioning) {}

    public function topic(): string
    {
        return 'provisioning.activate-service';
    }

    public function label(): string
    {
        return 'Provisioning: Activate service';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $cfg = $context->config();
        $commands = $this->provisioning->broadcast(
            subscriptionId: (string) $context->businessKey(),
            action: 'ACTIVATE',
            commands: [[
                'target_code' => $cfg['target'] ?? 'DEFAULT_NMS',
                'service_ref' => $context->var('packageRef') ?? ($cfg['serviceRef'] ?? null),
                'desired_state' => [
                    'desiredStatus' => 'ACTIVE',
                    'speedProfile' => $cfg['speedProfile'] ?? null,
                    'forceFail' => (bool) $context->var('forceProvisionFail', false),
                ],
            ]],
        );

        $confirmed = collect($commands)->every(fn ($c) => $c->status === 'CONFIRMED');

        return $confirmed
            ? TaskResult::success(['provisioned' => true, 'provisioningRefs' => collect($commands)->pluck('external_ref')->all()])
            : TaskResult::fail('Provisioning command was rejected by the NMS', retryable: true);
    }
}
