<?php

namespace Modules\Provisioning\Workflow;

use Modules\Provisioning\Services\ProvisioningService;
use Modules\Workflow\Contracts\Io;
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

    /** @return array<int,array<string,mixed>> */
    public function inputs(): array
    {
        return [
            Io::in('target', Io::STRING, 'NMS/target system code to provision against.', false, 'DEFAULT_NMS'),
            Io::in('speedProfile', Io::STRING, 'Service speed/bandwidth profile to apply (e.g. 100M).'),
            Io::in('serviceRef', Io::STRING, 'Service reference when not derived from the subscription package.'),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function outputs(): array
    {
        return [
            Io::out('provisioned', Io::BOOLEAN, 'True when the NMS confirmed activation.'),
            Io::out('provisioningRefs', Io::OBJECT, 'External references returned by the NMS for the confirmed commands.'),
        ];
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
