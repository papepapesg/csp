<?php

namespace Modules\WorkOrder\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Illuminate\Support\Facades\DB;
use Modules\WorkOrder\Events\WorkOrderEvents;
use Modules\WorkOrder\Models\WoFinalizationRequirement;
use Modules\WorkOrder\Models\WoNote;
use Modules\WorkOrder\Models\WoNoteKind;
use Modules\WorkOrder\Models\WorkOrder;

/**
 * WO-01 work-order lifecycle (create → assign → start → finalize / cancel).
 * Each transition records status history and emits the matching event.
 */
class WorkOrderService
{
    public function __construct(private readonly EventBus $events) {}

    /**
     * Allowed status transitions (WO-01 §3 generic state machine). Reassign is NOT a
     * status transition — it changes assignment at the same status (see reassign()).
     */
    private const TRANSITIONS = [
        WorkOrder::PENDING => [WorkOrder::ASSIGNED, WorkOrder::CANCELLED],
        WorkOrder::ASSIGNED => [WorkOrder::IN_PROGRESS, WorkOrder::CANCELLED],
        WorkOrder::IN_PROGRESS => [WorkOrder::FINALIZATION_PENDING, WorkOrder::CANCELLED],
        WorkOrder::FINALIZATION_PENDING => [WorkOrder::COMPLETED, WorkOrder::IN_PROGRESS, WorkOrder::CANCELLED],
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

    /**
     * WO-01 §1.7 reassign: change contractor/team/tech WITHOUT changing status
     * (ASSIGNED→ASSIGNED, or IN_PROGRESS→IN_PROGRESS "Pattern B" install→maintenance).
     * Recorded in wo_assignment_history; emits WorkOrderReassigned.
     *
     * @param  array<string,mixed>  $assignment
     */
    public function reassign(WorkOrder $wo, array $assignment, ?string $reason = null, ?string $actor = null): WorkOrder
    {
        if (! in_array($wo->status, [WorkOrder::ASSIGNED, WorkOrder::IN_PROGRESS], true)) {
            throw DomainException::conflict("Cannot reassign a work order in status {$wo->status}.");
        }

        return DB::transaction(function () use ($wo, $assignment, $reason, $actor) {
            $wo->assignmentHistory()->create([
                'prev_contractor_id' => $wo->contractor_id,
                'prev_team_id' => $wo->team_id,
                'prev_assigned_technician_id' => $wo->assigned_technician_id,
                'contractor_id' => $assignment['contractor_id'] ?? $wo->contractor_id,
                'team_id' => $assignment['team_id'] ?? $wo->team_id,
                'assigned_technician_id' => $assignment['assigned_technician_id'] ?? $wo->assigned_technician_id,
                'reason' => $reason,
                'changed_by' => $actor,
            ]);
            $wo->update(array_filter([
                'contractor_id' => $assignment['contractor_id'] ?? null,
                'team_id' => $assignment['team_id'] ?? null,
                'assigned_technician_id' => $assignment['assigned_technician_id'] ?? null,
            ], fn ($v) => $v !== null));

            $this->emit(WorkOrderEvents::REASSIGNED, $wo, ['contractorId' => $wo->contractor_id, 'teamId' => $wo->team_id, 'reason' => $reason]);

            return $wo->refresh();
        });
    }

    /**
     * WO-01 §1.3 add a structured note. The payload is validated against the
     * note_kind's registered JSON schema (required keys); free-text kinds (no schema)
     * accept a body. Append-only.
     *
     * @param  array<string,mixed>  $payload
     */
    public function addNote(WorkOrder $wo, string $noteKind, array $payload = [], ?string $body = null, ?string $author = null): WoNote
    {
        $kind = WoNoteKind::resolve($wo->operator_code, $noteKind);
        $required = $kind?->schema_jsonb['required'] ?? [];
        $missing = array_values(array_filter($required, fn ($key) => ! array_key_exists($key, $payload)));
        if ($missing) {
            throw DomainException::ruleRejected(
                'NOTE_SCHEMA_INVALID',
                "Note '{$noteKind}' is missing required fields: ".implode(', ', $missing),
            );
        }

        $note = $wo->notes()->create([
            'note_kind' => $noteKind,
            'body' => $body,
            'payload' => $payload ?: null,
            'author_id' => $author,
        ]);
        $this->emit(WorkOrderEvents::NOTE_APPENDED, $wo, ['noteId' => $note->id, 'noteKind' => $noteKind]);

        return $note;
    }

    /**
     * WO-01 §3 2-step finalize, first confirm: IN_PROGRESS → FINALIZATION_PENDING.
     * Saves the final reason + evidence; the checklist is enforced at second confirm.
     *
     * @param  array<string,mixed>  $evidence
     */
    public function finalizeFirstConfirm(WorkOrder $wo, array $evidence = [], ?string $actor = null): WorkOrder
    {
        return $this->transition($wo, WorkOrder::FINALIZATION_PENDING, $actor, [
            'resolution_code' => $evidence['resolution_code'] ?? null,
            'final_reason' => $evidence['final_reason'] ?? null,
            'findings' => $evidence['findings'] ?? null,
        ], WorkOrderEvents::FINALIZATION_PENDING);
    }

    /**
     * WO-01 §3/§4.4 second confirm: runs the finalization checklist (required note
     * kinds present) and, if it passes, FINALIZATION_PENDING → COMPLETED. Missing
     * items → 422 FINALIZATION_CHECKLIST_FAILED.
     */
    public function finalizeSecondConfirm(WorkOrder $wo, ?string $actor = null): WorkOrder
    {
        $this->enforceFinalizationChecklist($wo);

        return $this->transition($wo, WorkOrder::COMPLETED, $actor, ['finalized_at' => now()], WorkOrderEvents::FINALIZED, $wo->final_reason);
    }

    /**
     * Convenience one-shot finalize used by callers/flows that don't expose the
     * 2-step UI: runs first-confirm then second-confirm (still passing through
     * FINALIZATION_PENDING + the checklist).
     *
     * @param  array<string,mixed>  $evidence
     */
    public function finalize(WorkOrder $wo, array $evidence = [], ?string $actor = null): WorkOrder
    {
        $this->finalizeFirstConfirm($wo, $evidence, $actor);

        return $this->finalizeSecondConfirm($wo->refresh(), $actor);
    }

    /** §4.4: every required_note_kind must have at least one note on the WO. */
    private function enforceFinalizationChecklist(WorkOrder $wo): void
    {
        $req = WoFinalizationRequirement::resolveFor($wo->operator_code, $wo->kind ?? 'SUPPORT', $wo->job_type_code);
        if (! $req) {
            return; // no configured checklist for this (operator, kind, job_type)
        }
        $present = $wo->notes()->pluck('note_kind')->unique()->all();
        $missing = array_values(array_diff($req->required_note_kinds ?? [], $present));
        if ($missing) {
            throw DomainException::ruleRejected(
                'FINALIZATION_CHECKLIST_FAILED',
                'Missing required notes before finalization: '.implode(', ', $missing),
            );
        }
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
