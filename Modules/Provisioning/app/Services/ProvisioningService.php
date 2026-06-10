<?php

namespace Modules\Provisioning\Services;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Support\Facades\DB;
use Modules\Provisioning\Events\ProvisioningEvents;
use Modules\Provisioning\Models\ProvisioningCommand;
use Modules\Provisioning\Models\ProvisioningCommandAttempt;
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
        private readonly ProvisioningAdapterRegistry $adapters,
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
            $attemptNo = $command->attempts + 1;
            $command->update(['status' => ProvisioningCommand::SENT, 'attempts' => $attemptNo, 'sent_at' => now()]);
            $this->emit(ProvisioningEvents::COMMAND_SENT, $command);

            // Resolve the vendor adapter for THIS command's target (PROV-INT-01 §10.2).
            $adapter = $this->adapters->forCommand($command);
            $startedAt = microtime(true);
            $result = $adapter->dispatch($command);

            // §10.4 record the attempt (adapter, outcome, duration) for audit/retry.
            ProvisioningCommandAttempt::query()->create([
                'operator_code' => $command->operator_code,
                'command_id' => $command->command_id,
                'attempt_no' => $attemptNo,
                'adapter_class' => $adapter::class,
                'status' => $result->ok ? 'SUCCESS' : 'FAILED_RETRYABLE',
                'response_payload' => $result->ok ? $result->response : ['error' => $result->error],
                'vendor_status_code' => $result->ok ? 'OK' : null,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error_code' => $result->ok ? null : 'ADAPTER_REJECTED',
            ]);

            if ($result->ok && $result->async) {
                // §7.2: vendor accepted; the status worker resolves the final outcome.
                $command->update([
                    'status' => ProvisioningCommand::ACCEPTED,
                    'execution_mode' => 'ASYNC_ACCEPTED',
                    'external_ref' => $result->externalRef,
                    'response' => $result->response,
                    'accepted_at' => now(),
                    'last_error' => null,
                ]);
                $this->emit(ProvisioningEvents::COMMAND_SENT, $command);
            } elseif ($result->ok) {
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
                // FAILED_FINAL vs FAILED_RETRYABLE (§9); legacy FAILED retained as the
                // stored value, with the granularity in last_error context.
                $command->update(['status' => ProvisioningCommand::FAILED, 'last_error' => $result->error]);
                $this->emit(ProvisioningEvents::COMMAND_FAILED, $command);
            }

            return $command->refresh();
        });
    }

    /**
     * PROV §7.2 status worker: poll ACCEPTED (async) commands for their final
     * outcome and resolve them to CONFIRMED or FAILED. Returns counts.
     *
     * @return array{polled:int, resolved:int}
     */
    public function pollAsyncCommands(?string $operator = null): array
    {
        $operator ??= \App\Foundation\Support\Context::operatorCode();
        $polled = $resolved = 0;
        ProvisioningCommand::query()
            ->where('operator_code', $operator)
            ->where('status', ProvisioningCommand::ACCEPTED)
            ->get()
            ->each(function (ProvisioningCommand $command) use (&$polled, &$resolved) {
                $polled++;
                $result = $this->adapters->forCommand($command)->pollStatus($command);
                if ($result === null) {
                    return; // still pending
                }
                if ($result->ok) {
                    $command->update([
                        'status' => ProvisioningCommand::CONFIRMED,
                        'response' => $result->response,
                        'observed_state' => ['observedStatus' => $result->response['observedStatus'] ?? null],
                        'confirmed_at' => now(),
                    ]);
                    $this->recordDesiredState($command);
                    $this->emit(ProvisioningEvents::COMMAND_CONFIRMED, $command);
                } else {
                    $command->update(['status' => ProvisioningCommand::FAILED, 'last_error' => $result->error]);
                    $this->emit(ProvisioningEvents::COMMAND_FAILED, $command);
                }
                $resolved++;
            });

        return ['polled' => $polled, 'resolved' => $resolved];
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
