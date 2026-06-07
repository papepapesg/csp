<?php

namespace Modules\WorkOrder\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Illuminate\Support\Facades\DB;
use Modules\WorkOrder\Events\WorkOrderEvents;
use Modules\WorkOrder\Models\WorkOrder;

/**
 * WO-01 work-order lifecycle (create → assign → start → finalize / cancel).
 * Each transition records status history and emits the matching event.
 */
class WorkOrderService
{
    public function __construct(private readonly EventBus $events) {}

    /** Allowed status transitions. */
    private const TRANSITIONS = [
        WorkOrder::PENDING => [WorkOrder::ASSIGNED, WorkOrder::CANCELLED],
        WorkOrder::ASSIGNED => [WorkOrder::IN_PROGRESS, WorkOrder::CANCELLED],
        WorkOrder::IN_PROGRESS => [WorkOrder::FINALIZED, WorkOrder::CANCELLED],
    ];

    /** @param array<string,mixed> $data */
    public function create(array $data): WorkOrder
    {
        return DB::transaction(function () use ($data) {
            $wo = WorkOrder::query()->create($data + ['status' => WorkOrder::PENDING]);
            $this->recordHistory($wo, null, WorkOrder::PENDING, $data['created_by'] ?? null);
            $this->emit(WorkOrderEvents::CREATED, $wo, ['type' => $wo->type, 'accountId' => $wo->account_id]);

            return $wo;
        });
    }

    /** @param array<string,mixed> $assignment */
    public function assign(WorkOrder $wo, array $assignment, ?string $actor = null): WorkOrder
    {
        return $this->transition($wo, WorkOrder::ASSIGNED, $actor, array_merge($assignment, [
            'assigned_at' => now(),
        ]), WorkOrderEvents::ASSIGNED);
    }

    public function start(WorkOrder $wo, ?string $actor = null): WorkOrder
    {
        return $this->transition($wo, WorkOrder::IN_PROGRESS, $actor, ['started_at' => now()], WorkOrderEvents::STARTED);
    }

    /** @param array<string,mixed> $evidence */
    public function finalize(WorkOrder $wo, array $evidence = [], ?string $actor = null): WorkOrder
    {
        return $this->transition($wo, WorkOrder::FINALIZED, $actor, [
            'finalized_at' => now(),
            'resolution_code' => $evidence['resolution_code'] ?? null,
            'findings' => $evidence['findings'] ?? null,
        ], WorkOrderEvents::FINALIZED);
    }

    public function cancel(WorkOrder $wo, ?string $reason = null, ?string $actor = null): WorkOrder
    {
        return $this->transition($wo, WorkOrder::CANCELLED, $actor, [], WorkOrderEvents::CANCELLED, $reason);
    }

    /**
     * WO-01-FLOW-SUPPORT close: the support flow's enforce-checklist + state-event
     * steps drive the WO to its terminal state irrespective of the lean
     * desk-resolution path (which may not pass through ASSIGNED/IN_PROGRESS). Sets
     * the final_reason + phase, records history, and emits Finalized.
     *
     * @param  array<string,mixed>  $attrs
     */
    public function completeSupport(WorkOrder $wo, array $attrs = [], ?string $actor = null): WorkOrder
    {
        return DB::transaction(function () use ($wo, $attrs, $actor) {
            $from = $wo->status;
            $wo->update($attrs + ['status' => WorkOrder::FINALIZED, 'finalized_at' => now()]);
            $this->recordHistory($wo, $from, WorkOrder::FINALIZED, $actor, $attrs['final_reason'] ?? null);
            $this->emit(WorkOrderEvents::FINALIZED, $wo, ['from' => $from, 'to' => WorkOrder::FINALIZED, 'finalReason' => $wo->final_reason]);

            return $wo->refresh();
        });
    }

    /** Emit a WO domain event (used by the support-flow step handlers). */
    public function publish(string $type, WorkOrder $wo, array $payload = []): void
    {
        $this->emit($type, $wo, $payload);
    }

    /**
     * @param  array<string,mixed>  $attrs
     */
    private function transition(WorkOrder $wo, string $to, ?string $actor, array $attrs, string $event, ?string $reason = null): WorkOrder
    {
        $allowed = self::TRANSITIONS[$wo->status] ?? [];
        if (! in_array($to, $allowed, true)) {
            throw DomainException::conflict("Cannot transition work order from {$wo->status} to {$to}.");
        }

        return DB::transaction(function () use ($wo, $to, $actor, $attrs, $event, $reason) {
            $from = $wo->status;
            $wo->update($attrs + ['status' => $to]);
            $this->recordHistory($wo, $from, $to, $actor, $reason);
            $this->emit($event, $wo, ['from' => $from, 'to' => $to]);

            return $wo->refresh();
        });
    }

    private function recordHistory(WorkOrder $wo, ?string $from, string $to, ?string $actor, ?string $reason = null): void
    {
        $wo->statusHistory()->create([
            'prev_status' => $from,
            'new_status' => $to,
            'reason' => $reason,
            'changed_by' => $actor,
            'changed_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function emit(string $type, WorkOrder $wo, array $payload): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: WorkOrderEvents::TOPIC,
            payload: array_merge(['workOrderId' => $wo->work_order_id], $payload),
            aggregateType: 'WorkOrder',
            aggregateId: $wo->work_order_id,
        ));
    }
}
