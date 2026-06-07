<?php

namespace Modules\Provisioning\Services;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Support\Facades\DB;
use Modules\Provisioning\Contracts\ProvisioningAdapter;
use Modules\Provisioning\Events\ProvisioningEvents;
use Modules\Provisioning\Models\ProvisioningCommand;
use Modules\Provisioning\Models\ProvisioningDesiredState;

/**
 * PROV-INT-01 command dispatch + reconciliation. Owns the command ledger; turns
 * a desired-state request from an owning module into one or more technical
 * commands (broadcast), dispatches each via the configured adapter, and tracks
 * status. NOC sees every command in one place.
 */
class ProvisioningService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly ProvisioningAdapter $adapter,
    ) {}

    /**
     * Issue a provisioning action as a broadcast of one command per target.
     *
     * @param  array<int,array{target_code:string,service_ref?:string,desired_state?:array<string,mixed>}>  $commands
     * @return array<int,ProvisioningCommand>
     */
    public function broadcast(string $subscriptionId, string $action, array $commands): array
    {
        $broadcastId = Id::make('bcast');
        $issued = [];

        foreach ($commands as $spec) {
            $command = ProvisioningCommand::query()->create([
                'broadcast_id' => $broadcastId,
                'subscription_id' => $subscriptionId,
                'service_ref' => $spec['service_ref'] ?? null,
                'action' => $action,
                'target_code' => $spec['target_code'],
                'desired_state' => $spec['desired_state'] ?? ['desiredStatus' => 'ACTIVE'],
                'status' => ProvisioningCommand::PENDING,
                'correlation_id' => Context::correlationId(),
                'request' => ['action' => $action, 'desiredState' => $spec['desired_state'] ?? null],
            ]);

            $this->dispatch($command);
            $issued[] = $command->refresh();
        }

        return $issued;
    }

    /** Dispatch (or retry) a single command via the adapter. */
    public function dispatch(ProvisioningCommand $command): ProvisioningCommand
    {
        return DB::transaction(function () use ($command) {
            $command->update(['status' => ProvisioningCommand::SENT, 'attempts' => $command->attempts + 1, 'sent_at' => now()]);
            $this->emit(ProvisioningEvents::COMMAND_SENT, $command);

            $result = $this->adapter->dispatch($command);

            if ($result->ok) {
                $command->update([
                    'status' => ProvisioningCommand::CONFIRMED,
                    'external_ref' => $result->externalRef,
                    'response' => $result->response,
                    'observed_state' => ['observedStatus' => $result->response['observedStatus'] ?? null],
                    'confirmed_at' => now(),
                    'last_error' => null,
                ]);
                $this->recordDesiredState($command);
                $this->emit(ProvisioningEvents::COMMAND_CONFIRMED, $command);
            } else {
                $command->update(['status' => ProvisioningCommand::FAILED, 'last_error' => $result->error]);
                $this->emit(ProvisioningEvents::COMMAND_FAILED, $command);
            }

            return $command->refresh();
        });
    }

    /**
     * Reconcile desired vs observed for confirmed commands; flag mismatches.
     * (PROV-INT-01 reconciliation worker — simplified.)
     */
    public function reconcile(): int
    {
        $mismatches = 0;
        $commands = ProvisioningCommand::query()->where('status', ProvisioningCommand::CONFIRMED)->get();
        foreach ($commands as $command) {
            $desired = $command->desired_state['desiredStatus'] ?? null;
            $observed = $command->observed_state['observedStatus'] ?? null;
            if ($desired && $observed && $desired !== $observed) {
                $command->update(['status' => ProvisioningCommand::MISMATCH]);
                $this->emit(ProvisioningEvents::RECONCILE_MISMATCH, $command);
                $mismatches++;
            }
        }

        return $mismatches;
    }

    /**
     * Snapshot BSS desired technical state from a confirmed command, so the
     * reconciliation run has a comparison base (PROV-INT-01 §10.5, R-PROV-10).
     */
    public function recordDesiredState(ProvisioningCommand $command): void
    {
        $desired = $command->desired_state ?? [];
        $subscriberKey = ($command->subscription_id ?? 'unknown').':'.($command->service_ref ?? 'DEFAULT');

        ProvisioningDesiredState::query()->updateOrCreate(
            [
                'operator_code' => $command->operator_code,
                'subscription_id' => $command->subscription_id,
                'service_ref' => $command->service_ref,
                'target_code' => $command->target_code,
            ],
            [
                'desired_state_id' => Id::make('pds'),
                'subscriber_key' => $subscriberKey,
                'desired_status' => $desired['desiredStatus'] ?? 'ACTIVE',
                'desired_profile' => $desired,
                'source_module' => 'PROV-INT',
                'source_ref' => $command->command_id,
                'effective_from' => now(),
            ],
        );
    }

    private function emit(string $type, ProvisioningCommand $command): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: ProvisioningEvents::TOPIC,
            payload: [
                'commandId' => $command->command_id,
                'subscriptionId' => $command->subscription_id,
                'action' => $command->action,
                'target' => $command->target_code,
                'status' => $command->status,
            ],
            aggregateType: 'ProvisioningCommand',
            aggregateId: $command->command_id,
        ));
    }
}
