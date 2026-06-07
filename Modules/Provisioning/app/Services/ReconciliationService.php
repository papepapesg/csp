<?php

namespace Modules\Provisioning\Services;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Id;
use Illuminate\Support\Facades\DB;
use Modules\Provisioning\Contracts\ProvisioningAdapter;
use Modules\Provisioning\Events\ProvisioningEvents;
use Modules\Provisioning\Models\ProvisioningDesiredState;
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
        private readonly ProvisioningAdapter $adapter,
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
            $observed = $this->adapter->fetchObserved(
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
     * NOC force-sync of a mismatch: re-issue the desired state to the target to
     * bring the network back in line, then resolve the item. Permission-gated by
     * the caller (R-PROV-07) and audited via the emitted event (R-PROV-09).
     */
    public function forceSync(ProvisioningReconciliationItem $item, ?string $actor = null): ProvisioningReconciliationItem
    {
        $desired = ProvisioningDesiredState::query()
            ->where('subscriber_key', $item->subscriber_key)
            ->where('target_code', $item->target_code)
            ->first();

        $this->events->publish(new DomainEvent(
            type: ProvisioningEvents::FORCE_SYNC_REQUESTED,
            topic: ProvisioningEvents::TOPIC,
            payload: ['itemId' => $item->item_id, 'target' => $item->target_code, 'subscriberKey' => $item->subscriber_key, 'actor' => $actor],
            aggregateType: 'ProvisioningReconciliationItem',
            aggregateId: $item->item_id,
        ));

        if ($desired) {
            $this->provisioning->broadcast($desired->subscription_id, 'FORCE_SYNC', [[
                'target_code' => $desired->target_code,
                'service_ref' => $desired->service_ref,
                'desired_state' => $desired->desired_profile ?? ['desiredStatus' => $desired->desired_status],
            ]]);
        }

        $item->update([
            'status' => ProvisioningReconciliationItem::RESOLVED,
            'resolution' => 'FORCE_SYNCED',
            'resolved_by' => $actor,
            'resolved_at' => now(),
        ]);

        return $item->refresh();
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
