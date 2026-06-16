<?php

namespace Modules\Subscription\Workflow;

use Modules\Provisioning\Services\ProvisioningService;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Models\SubscriptionOperation;
use Modules\Workflow\Contracts\Io;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * SUB-WF-FRAMEWORK ful-call-* step. During the commit window, sends the network/
 * fulfillment command (FUL/PROV-INT) for the operation — e.g. AAA suspend, FUL-03
 * reactivate — via the swappable provisioning adapter, and GATES the commit on it
 * (a rejected NMS fails the operation, so the subscription never lands the final
 * status on a network that did not change). Narrates FULFILLMENT_CALL.
 *   config: { action: 'SUSPEND', target: 'DEFAULT_NMS', desiredStatus: 'SUSPENDED' }
 */
class FulfillmentCallHandler implements TaskHandler
{
    public function __construct(private readonly ProvisioningService $provisioning) {}

    public function topic(): string
    {
        return 'sub.fulfillment-call';
    }

    public function label(): string
    {
        return 'Subscription: Fulfillment call (network)';
    }

    /** @return array<int,array<string,mixed>> */
    public function inputs(): array
    {
        return [
            Io::in('action', Io::ENUM, 'Network command to send for this operation.', false, 'MODIFY', ['ACTIVATE', 'SUSPEND', 'MODIFY', 'DEACTIVATE']),
            Io::in('target', Io::STRING, 'NMS/target system code.', false, 'DEFAULT_NMS'),
            Io::in('desiredStatus', Io::STRING, 'State the network should reach.', false, 'ACTIVE'),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function outputs(): array
    {
        return [Io::out('fulfilled', Io::BOOLEAN, 'True when every network command was CONFIRMED (the commit is gated on this).')];
    }

    public function handle(TaskContext $context): TaskResult
    {
        $subscription = Subscription::query()->find($context->businessKey());
        if (! $subscription) {
            return TaskResult::fail('Subscription not found', retryable: false);
        }

        SubscriptionOperation::narrate($context->var('operationId'), SubscriptionOperation::FULFILLMENT_CALL);

        $cfg = $context->config();
        $commands = $this->provisioning->broadcast(
            subscriptionId: (string) $subscription->subscription_id,
            action: $cfg['action'] ?? 'MODIFY',
            commands: [[
                'target_code' => $cfg['target'] ?? 'DEFAULT_NMS',
                'service_ref' => $subscription->package_ref,
                'desired_state' => ['desiredStatus' => $cfg['desiredStatus'] ?? 'ACTIVE'],
            ]],
        );

        $confirmed = collect($commands)->every(fn ($c) => $c->status === 'CONFIRMED');

        return $confirmed
            ? TaskResult::success(['fulfilled' => true])
            : TaskResult::fail('Fulfillment/network call was rejected', retryable: true);
    }
}
