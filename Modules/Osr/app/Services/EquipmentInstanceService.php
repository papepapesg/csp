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
        EquipmentInstance::IN_CONTRACTOR_STOCK => [EquipmentInstance::IN_FIELD_ACTIVE, EquipmentInstance::IN_MAIN_WAREHOUSE, EquipmentInstance::FAULTY],
        EquipmentInstance::IN_FIELD_ACTIVE => [EquipmentInstance::RETURNED, EquipmentInstance::FAULTY],
        EquipmentInstance::RETURNED => [EquipmentInstance::IN_MAIN_WAREHOUSE, EquipmentInstance::FAULTY, EquipmentInstance::RETIRED],
        EquipmentInstance::FAULTY => [EquipmentInstance::RETIRED, EquipmentInstance::IN_MAIN_WAREHOUSE],
    ];

    public function __construct(private readonly EventBus $events) {}

    /** @param array<string,mixed> $data */
    public function register(array $data): EquipmentInstance
    {
        return DB::transaction(function () use ($data) {
            $instance = EquipmentInstance::query()->create($data + ['state' => EquipmentInstance::IN_MAIN_WAREHOUSE]);
            $this->logEvent($instance, 'REGISTERED', null, $instance->state, $instance->location_id);

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
        $allowed = self::TRANSITIONS[$instance->state] ?? [];
        if (! in_array($toState, $allowed, true)) {
            throw DomainException::conflict("Cannot transition equipment from {$instance->state} to {$toState}.");
        }

        return DB::transaction(function () use ($instance, $toState, $context) {
            $from = $instance->state;
            $instance->update([
                'state' => $toState,
                'location_id' => $context['location_id'] ?? $instance->location_id,
                'customer_id' => $context['customer_id'] ?? $instance->customer_id,
                'subscription_id' => $context['subscription_id'] ?? $instance->subscription_id,
            ]);

            $this->logEvent($instance, 'STATE_CHANGE', $from, $toState, $instance->location_id, $context['reference'] ?? null);

            $this->events->publish(new DomainEvent(
                type: OsrEvents::INSTANCE_STATE_CHANGED,
                topic: OsrEvents::TOPIC,
                payload: ['instanceId' => $instance->instance_id, 'from' => $from, 'to' => $toState],
                aggregateType: 'EquipmentInstance',
                aggregateId: $instance->instance_id,
            ));

            return $instance->refresh();
        });
    }

    private function logEvent(EquipmentInstance $instance, string $type, ?string $from, ?string $to, ?string $location, ?string $ref = null): void
    {
        $instance->lifecycleEvents()->create([
            'event_type' => $type,
            'from_state' => $from,
            'to_state' => $to,
            'location_id' => $location,
            'reference' => $ref,
            'created_at' => now(),
        ]);
    }
}
