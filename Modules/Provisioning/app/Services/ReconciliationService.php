<?php

namespace Modules\Provisioning\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Id;
use Illuminate\Support\Facades\DB;
use Modules\Provisioning\Events\ProvisioningEvents;
use Modules\Provisioning\Models\ProvisioningDesiredState;
use Modules\Provisioning\Models\ProvisioningForceSyncRequest;
use Modules\Provisioning\Models\ProvisioningObservedState;
use Modules\Provisioning\Models\ProvisioningReconciliationItem;
use Modules\Provisioning\Models\ProvisioningReconciliationRun;

/**
 * PROV-INT-01 §7.3 reconciliation worker. Loads BSS desired state, polls each
 * target for observed state, compares them, and records every mismatch as a
 * review item. Mismatches do NOT auto-fix (R-PROV-08); NOC drives force-sync,
 * which is permission-gated + audited (R-PROV-07/09).
 */
class ReconciliationService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly ProvisioningAdapterRegistry $adapters,
        private readonly ProvisioningService $provisioning,
    ) {}

    /**
     * Run a reconciliation pass over the desired states (optionally a single
     * target), returning the completed run.
     */
    public function run(?string $targetCode = null, ?string $operator = null): ProvisioningReconciliationRun
    {
        $run = ProvisioningReconciliationRun::query()->create([
            'run_id' => Id::make('prr'),
            'operator_code' => $operator,
            'target_code' => $targetCode,
            'scope_type' => $targetCode ? 'FULL_TARGET' : 'FULL_TARGET',
            'scope_value' => $targetCode,
            'status' => ProvisioningReconciliationRun::RUNNING,
            'started_at' => now(),
        ]);

        $desiredStates = ProvisioningDesiredState::query()
            ->when($targetCode, fn ($q) => $q->where('target_code', $targetCode))
            ->when($operator, fn ($q) => $q->where('operator_code', $operator))
            ->get();

        $observedCount = 0;
        $mismatchCount = 0;

        foreach ($desiredStates as $desired) {
            // Poll each target through ITS adapter (PROV-INT-01 §10.2).
            $observed = $this->adapters->forTarget((string) $desired->operator_code, (string) $desired->target_code)->fetchObserved(
                $desired->target_code,
                $desired->subscriber_key,
                $desired->desired_status,
                $desired->desired_profile ?? [],
            );

            $observedStatus = $observed['observedStatus'] ?? null;
            if ($observed !== null) {
                $observedCount++;
                $this->upsertObserved($desired, $observed, $run->run_id);
            }

            if ($this->isMismatch($desired->desired_status, $observedStatus)) {
                $this->openItem($run, $desired, $observedStatus);
                $mismatchCount++;
            } else {
                $this->resolveMatchedItems($desired);
            }
        }

        $run->update([
            'status' => ProvisioningReconciliationRun::COMPLETED,
            'desired_count' => $desiredStates->count(),
            'observed_count' => $observedCount,
            'mismatch_count' => $mismatchCount,
            'completed_at' => now(),
        ]);

        $this->events->publish(new DomainEvent(
            type: ProvisioningEvents::RECONCILE_RUN_COMPLETED,
            topic: ProvisioningEvents::TOPIC,
            payload: ['runId' => $run->run_id, 'target' => $targetCode, 'desired' => $desiredStates->count(), 'mismatches' => $mismatchCount],
            aggregateType: 'ProvisioningReconciliationRun',
            aggregateId: $run->run_id,
        ));

        return $run->refresh();
    }

    /**
     * R-PROV-07/08: a mismatch does NOT auto-fix. NOC raises a force-sync REQUEST
     * (PENDING_APPROVAL) from the item; it is approved, then executed. This is the
     * request step — it does not touch the network.
     */
    public function requestForceSync(ProvisioningReconciliationItem $item, ?string $requestedBy = null, ?string $reason = null): ProvisioningForceSyncRequest
    {
        $request = ProvisioningForceSyncRequest::query()->create([
            'operator_code' => $item->operator_code,
            'source_item_id' => $item->item_id,
            'subscription_id' => $item->subscription_id,
            'service_ref' => $item->service_ref,
            'target_code' => $item->target_code,
            'requested_action' => 'REAPPLY_PROFILE',
            'status' => ProvisioningForceSyncRequest::PENDING_APPROVAL,
            'requested_by_user_id' => $requestedBy,
            'reason' => $reason,
        ]);
        $item->update(['status' => ProvisioningReconciliationItem::IN_REVIEW]);
        $this->emitForceSync(ProvisioningEvents::FORCE_SYNC_REQUESTED, $request);

        return $request;
    }

    /** R-PROV-07: approve a pending force-sync (the EM-CFG-04 approval step). */
    public function approveForceSync(ProvisioningForceSyncRequest $request, ?string $approver = null): ProvisioningForceSyncRequest
    {
        if ($request->status !== ProvisioningForceSyncRequest::PENDING_APPROVAL) {
            throw DomainException::conflict('Only a PENDING_APPROVAL force-sync can be approved.');
        }
        $request->update(['status' => ProvisioningForceSyncRequest::APPROVED, 'approved_by_user_id' => $approver]);
        $this->emitForceSync(ProvisioningEvents::FORCE_SYNC_APPROVED, $request);

        return $request->refresh();
    }

    /**
     * Execute an APPROVED force-sync: re-issue the desired state to the target via a
     * normal provisioning command (R-PROV-08 — audit consistent), then resolve the
     * source item. Rejects execution before approval (R-PROV-07).
     */
    public function executeForceSync(ProvisioningForceSyncRequest $request, ?string $actor = null): ProvisioningForceSyncRequest
    {
        if ($request->status !== ProvisioningForceSyncRequest::APPROVED) {
            throw DomainException::conflict('Force-sync must be APPROVED before execution.', nextAction: 'APPROVE_FIRST');
        }

        return DB::transaction(function () use ($request, $actor) {
            $request->update(['status' => ProvisioningForceSyncRequest::RUNNING]);

            $desired = ProvisioningDesiredState::query()
                ->where('subscription_id', $request->subscription_id)
                ->where('target_code', $request->target_code)
                ->first();

            $commandId = null;
            if ($desired) {
                $command = $this->provisioning->broadcast($desired->subscription_id, 'FORCE_SYNC', [[
                    'target_code' => $desired->target_code,
                    'service_ref' => $desired->service_ref,
                    'desired_state' => $desired->desired_profile ?? ['desiredStatus' => $desired->desired_status],
                ]])[0] ?? null;
                $commandId = $command?->command_id;
            }

            if ($request->source_item_id) {
                ProvisioningReconciliationItem::query()->whereKey($request->source_item_id)->update([
                    'status' => ProvisioningReconciliationItem::RESOLVED,
                    'resolution' => 'FORCE_SYNCED',
                    'resolved_by' => $actor,
                    'resolved_at' => now(),
                ]);
            }

            $request->update(['status' => ProvisioningForceSyncRequest::COMPLETED, 'command_id' => $commandId]);
            $this->emitForceSync(ProvisioningEvents::FORCE_SYNC_COMPLETED, $request);

            return $request->refresh();
        });
    }

    public function cancelForceSync(ProvisioningForceSyncRequest $request, ?string $actor = null): ProvisioningForceSyncRequest
    {
        if (in_array($request->status, [ProvisioningForceSyncRequest::COMPLETED, ProvisioningForceSyncRequest::CANCELLED], true)) {
            throw DomainException::conflict('Force-sync is already terminal.');
        }
        $request->update(['status' => ProvisioningForceSyncRequest::CANCELLED]);
        $this->emitForceSync(ProvisioningEvents::FORCE_SYNC_CANCELLED, $request);

        return $request->refresh();
    }

    private function emitForceSync(string $type, ProvisioningForceSyncRequest $request): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: ProvisioningEvents::TOPIC,
            payload: ['forceSyncId' => $request->force_sync_id, 'itemId' => $request->source_item_id, 'target' => $request->target_code, 'status' => $request->status],
            aggregateType: 'ProvisioningForceSyncRequest',
            aggregateId: $request->force_sync_id,
        ));
    }

    private function isMismatch(string $desiredStatus, ?string $observedStatus): bool
    {
        return $observedStatus === null || $desiredStatus !== $observedStatus;
    }

    /** @param array{observedStatus:?string,observedProfile:array<string,mixed>} $observed */
    private function upsertObserved(ProvisioningDesiredState $desired, array $observed, string $runId): void
    {
        ProvisioningObservedState::query()->updateOrCreate(
            ['target_code' => $desired->target_code, 'subscriber_key' => $desired->subscriber_key],
            [
                'observed_state_id' => Id::make('pos'),
                'operator_code' => $desired->operator_code,
                'observed_status' => $observed['observedStatus'] ?? null,
                'observed_profile' => $observed['observedProfile'] ?? [],
                'source_run_id' => $runId,
                'collected_at' => now(),
            ],
        );
    }

    private function openItem(ProvisioningReconciliationRun $run, ProvisioningDesiredState $desired, ?string $observedStatus): void
    {
        // Avoid duplicate open items for the same subscriber/target across runs.
        $existing = ProvisioningReconciliationItem::query()
            ->where('target_code', $desired->target_code)
            ->where('subscriber_key', $desired->subscriber_key)
            ->where('status', ProvisioningReconciliationItem::OPEN)
            ->first();

        $item = DB::transaction(function () use ($run, $desired, $observedStatus, $existing) {
            if ($existing) {
                $existing->update(['run_id' => $run->run_id, 'observed_status' => $observedStatus]);

                return $existing;
            }

            return ProvisioningReconciliationItem::query()->create([
                'item_id' => Id::make('pri'),
                'operator_code' => $desired->operator_code,
                'run_id' => $run->run_id,
                'target_code' => $desired->target_code,
                'subscription_id' => $desired->subscription_id,
                'service_ref' => $desired->service_ref,
                'subscriber_key' => $desired->subscriber_key,
                'desired_status' => $desired->desired_status,
                'observed_status' => $observedStatus,
                'diff' => ['desiredStatus' => $desired->desired_status, 'observedStatus' => $observedStatus],
                'status' => ProvisioningReconciliationItem::OPEN,
            ]);
        });

        if (! $existing) {
            $this->events->publish(new DomainEvent(
                type: ProvisioningEvents::RECONCILE_ITEM_OPENED,
                topic: ProvisioningEvents::TOPIC,
                payload: ['itemId' => $item->item_id, 'target' => $desired->target_code, 'subscriberKey' => $desired->subscriber_key, 'desired' => $desired->desired_status, 'observed' => $observedStatus],
                aggregateType: 'ProvisioningReconciliationItem',
                aggregateId: $item->item_id,
            ));
        }
    }

    /** Auto-close open items that now match (R-PROV-08 de-escalation). */
    private function resolveMatchedItems(ProvisioningDesiredState $desired): void
    {
        ProvisioningReconciliationItem::query()
            ->where('target_code', $desired->target_code)
            ->where('subscriber_key', $desired->subscriber_key)
            ->where('status', ProvisioningReconciliationItem::OPEN)
            ->update([
                'status' => ProvisioningReconciliationItem::RESOLVED,
                'resolution' => 'MATCHED_SINCE',
                'resolved_at' => now(),
            ]);
    }
}
