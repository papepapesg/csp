<?php

namespace Modules\Osr\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Illuminate\Support\Facades\DB;
use Modules\Osr\Events\OsrEvents;
use Modules\Osr\Models\EquipmentInstance;

/**
 * OSR-INSTANCE-01 serialized equipment registry. Owns the instance master state
 * and the append-only lifecycle event ledger ("where is serial XYZ, and how did
 * it get there?").
 */
class EquipmentInstanceService
{
    /** Allowed state transitions. */
    private const TRANSITIONS = [
        EquipmentInstance::IN_MAIN_WAREHOUSE => [EquipmentInstance::IN_CONTRACTOR_STOCK, EquipmentInstance::RETIRED, EquipmentInstance::FAULTY],
        EquipmentInstance::IN_CONTRACTOR_STOCK => [EquipmentInstance::IN_FIELD_ACTIVE, EquipmentInstance::RESERVED_FOR_WO, EquipmentInstance::IN_MAIN_WAREHOUSE, EquipmentInstance::FAULTY],
        EquipmentInstance::RESERVED_FOR_WO => [EquipmentInstance::IN_FIELD_ACTIVE, EquipmentInstance::IN_CONTRACTOR_STOCK],
        EquipmentInstance::IN_FIELD_ACTIVE => [EquipmentInstance::IN_FIELD_DEFECTIVE, EquipmentInstance::RECOVERED_BY_CONTRACTOR, EquipmentInstance::RETURNED, EquipmentInstance::FAULTY],
        EquipmentInstance::IN_FIELD_DEFECTIVE => [EquipmentInstance::RECOVERED_BY_CONTRACTOR, EquipmentInstance::FAULTY],
        EquipmentInstance::RECOVERED_BY_CONTRACTOR => [EquipmentInstance::IN_CONTRACTOR_STOCK, EquipmentInstance::FAULTY, EquipmentInstance::RETIRED],
        EquipmentInstance::RETURNED => [EquipmentInstance::IN_MAIN_WAREHOUSE, EquipmentInstance::FAULTY, EquipmentInstance::RETIRED],
        EquipmentInstance::FAULTY => [EquipmentInstance::RETIRED, EquipmentInstance::IN_MAIN_WAREHOUSE],
    ];

    public function __construct(private readonly EventBus $events) {}

    /** @param array<string,mixed> $data */
    public function register(array $data): EquipmentInstance
    {
        // R-OSR-INST-2: only SKUs flagged is_serialized=TRUE in PLM-CFG-06 may have instances.
        $serialized = DB::table('equipment_sku')->where('sku_id', $data['sku_id'])->value('is_serialized');
        if ($serialized !== null && ! $serialized) {
            throw DomainException::ruleRejected('SKU_NOT_SERIALIZED', "SKU '{$data['sku_id']}' is not serialized; it cannot have equipment instances.");
        }

        return DB::transaction(function () use ($data) {
            $instance = EquipmentInstance::query()->create($data + ['state' => EquipmentInstance::IN_MAIN_WAREHOUSE]);
            $this->logEvent($instance, 'REGISTERED', null, $instance->state, $instance->location_id, reasonCode: 'VENDOR_RECEIPT');

            $this->events->publish(new DomainEvent(
                type: OsrEvents::INSTANCE_REGISTERED,
                topic: OsrEvents::TOPIC,
                payload: ['instanceId' => $instance->instance_id, 'serial' => $instance->serial, 'skuId' => $instance->sku_id],
                aggregateType: 'EquipmentInstance',
                aggregateId: $instance->instance_id,
            ));

            return $instance;
        });
    }

    /**
     * Move an instance to a new state (and optionally location / customer binding).
     *
     * @param  array<string,mixed>  $context  location_id?, customer_id?, subscription_id?, reference?
     */
    public function transition(EquipmentInstance $instance, string $toState, array $context = []): EquipmentInstance
    {
        // R-OSR-INST-9: DECOMMISSIONED/RETIRED is terminal — no further transitions.
        if (! $instance->active) {
            throw DomainException::conflict("Instance {$instance->instance_id} is decommissioned (terminal); no further transitions.");
        }
        $allowed = self::TRANSITIONS[$instance->state] ?? [];
        if (! in_array($toState, $allowed, true)) {
            throw DomainException::conflict("Cannot transition equipment from {$instance->state} to {$toState}.");
        }

        // R-OSR-INST-7: a recovery transition MUST record the recovering contractor — this is
        // the field downstream routing reads to send the unit back to the contractor's warehouse.
        $contractorId = $context['contractor_id'] ?? null;
        if ($toState === EquipmentInstance::RECOVERED_BY_CONTRACTOR && ! $contractorId) {
            throw DomainException::ruleRejected('CONTRACTOR_REQUIRED', 'A RECOVERED_BY_CONTRACTOR transition must record the recovering contractor_id.');
        }

        return DB::transaction(function () use ($instance, $toState, $context, $contractorId) {
            $from = $instance->state;
            $instance->update([
                'state' => $toState,
                'location_id' => $context['location_id'] ?? $instance->location_id,
                'customer_id' => $context['customer_id'] ?? $instance->customer_id,
                'subscription_id' => $context['subscription_id'] ?? $instance->subscription_id,
                // R-OSR-INST-9: terminal state keeps the row but marks it inactive.
                'active' => $toState === EquipmentInstance::RETIRED ? false : $instance->active,
            ]);

            $reasonCode = $context['reason_code'] ?? ($toState === EquipmentInstance::RECOVERED_BY_CONTRACTOR ? 'CONTRACTOR_RECOVERED_FROM_FIELD' : null);
            $this->logEvent($instance, 'STATE_CHANGE', $from, $toState, $instance->location_id, $context['reference'] ?? null, $contractorId, $reasonCode);

            $this->events->publish(new DomainEvent(
                type: OsrEvents::INSTANCE_STATE_CHANGED,
                topic: OsrEvents::TOPIC,
                payload: ['instanceId' => $instance->instance_id, 'from' => $from, 'to' => $toState, 'contractorId' => $contractorId],
                aggregateType: 'EquipmentInstance',
                aggregateId: $instance->instance_id,
            ));
            // OSR-INSTANCE-01 §6 named events for the specific downstream consumers.
            $this->emitNamed($instance, $from, $toState, $contractorId);

            return $instance->refresh();
        });
    }

    /** Emit the DD's specific lifecycle events (RMA routing, deposit charge/refund, decommission). */
    private function emitNamed(EquipmentInstance $instance, string $from, string $to, ?string $contractorId): void
    {
        $type = match (true) {
            $to === EquipmentInstance::RECOVERED_BY_CONTRACTOR => OsrEvents::INSTANCE_RECOVERED_BY_CONTRACTOR,
            $to === EquipmentInstance::IN_FIELD_ACTIVE && $from !== EquipmentInstance::IN_FIELD_DEFECTIVE => OsrEvents::INSTANCE_BOUND_TO_CUSTOMER,
            $from === EquipmentInstance::IN_FIELD_ACTIVE && $to !== EquipmentInstance::IN_FIELD_DEFECTIVE => OsrEvents::INSTANCE_UNBOUND_FROM_CUSTOMER,
            $to === EquipmentInstance::RETIRED => OsrEvents::INSTANCE_DECOMMISSIONED,
            default => null,
        };
        if ($type === null) {
            return;
        }
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: OsrEvents::TOPIC,
            payload: ['instanceId' => $instance->instance_id, 'serial' => $instance->serial, 'skuId' => $instance->sku_id, 'contractorId' => $contractorId, 'accountId' => $instance->customer_id],
            aggregateType: 'EquipmentInstance',
            aggregateId: $instance->instance_id,
        ));
    }

    private function logEvent(EquipmentInstance $instance, string $type, ?string $from, ?string $to, ?string $location, ?string $ref = null, ?string $contractorId = null, ?string $reasonCode = null): void
    {
        // R-OSR-INST-5: event_sequence is monotonic per instance (no gaps).
        $next = (int) $instance->lifecycleEvents()->max('event_sequence') + 1;
        $instance->lifecycleEvents()->create([
            'event_sequence' => $next,
            'event_type' => $type,
            'from_state' => $from,
            'to_state' => $to,
            'location_id' => $location,
            'reference' => $ref,
            'contractor_id' => $contractorId,
            'reason_code' => $reasonCode,
            'created_at' => now(),
        ]);
    }
}
