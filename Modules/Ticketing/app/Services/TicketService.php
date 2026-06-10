<?php

namespace Modules\Ticketing\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use Illuminate\Support\Facades\DB;
use Modules\Ticketing\Events\TicketEvents;
use Modules\Ticketing\Models\SlaPolicy;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\WorkOrder\Services\WorkOrderService;

/**
 * TCK-01 case lifecycle. Owns ticket status, assignment, comments and timeline.
 * When field intervention is needed it creates a WO (WO owns field execution)
 * and waits for WorkOrderFinalized before resolving (HLD §6.7).
 */
class TicketService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly WorkOrderService $workOrders,
    ) {}

    /** @param array<string,mixed> $data */
    public function create(array $data): Ticket
    {
        return DB::transaction(function () use ($data) {
            // TCK-01 §7.6: the category catalog supplies routing defaults (priority/queue).
            $category = TicketCategory::resolve(Context::operatorCode(), $data['category'] ?? null);
            $priority = $data['priority'] ?? $category?->default_priority ?? 'NORMAL';
            $ticket = Ticket::query()->create($data + [
                'priority' => $priority,
                'queue' => $data['queue'] ?? $category?->default_queue,
                'status' => Ticket::OPEN,
                'ticket_number' => $this->nextTicketNumber(Context::operatorCode()),
                'sla_due_at' => now()->addHours(SlaPolicy::resolveHours(Context::operatorCode(), $data['category'] ?? null, $priority)),
            ]);
            $this->timeline($ticket, 'CREATED', null, Ticket::OPEN, $data['opened_by'] ?? null);
            $this->emit(TicketEvents::CREATED, $ticket, ['category' => $ticket->category, 'priority' => $ticket->priority]);

            return $ticket;
        });
    }

    /** Gap-free human-facing ticket number per (operator, year): TCK-WIK-2026-000042. */
    private function nextTicketNumber(string $operator): string
    {
        $year = (int) now()->year;
        DB::table('ticket_number_sequence')->insertOrIgnore([
            'operator_code' => $operator, 'fiscal_year' => $year, 'last_value' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = DB::table('ticket_number_sequence')->where('operator_code', $operator)->where('fiscal_year', $year)->lockForUpdate()->first();
        $next = ((int) $row->last_value) + 1;
        DB::table('ticket_number_sequence')->where('operator_code', $operator)->where('fiscal_year', $year)->update(['last_value' => $next, 'updated_at' => now()]);

        return sprintf('TCK-%s-%d-%06d', $operator, $year, $next);
    }

    /** Attach a file (stored in FOUNDATION_FILE_STORAGE) to a ticket (TCK-01 §attachments). */
    public function addAttachment(Ticket $ticket, array $data, ?string $actor = null): object
    {
        $id = \App\Foundation\Support\Id::make('tatt');
        DB::table('ticket_attachment')->insert([
            'attachment_id' => $id, 'ticket_id' => $ticket->ticket_id,
            'file_id' => $data['file_id'], 'file_name' => $data['file_name'],
            'content_type' => $data['content_type'] ?? null, 'size_bytes' => $data['size_bytes'] ?? null,
            'uploaded_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->timeline($ticket, 'ATTACHMENT_ADDED', null, $ticket->status, $actor, ['fileName' => $data['file_name']]);

        return DB::table('ticket_attachment')->where('attachment_id', $id)->first();
    }

    /** Link the ticket to another entity (subscription/invoice/WO/ticket…). */
    public function linkEntity(Ticket $ticket, string $entityType, string $entityRef, string $relation = 'RELATED', ?string $actor = null): object
    {
        $id = \App\Foundation\Support\Id::make('tlnk');
        DB::table('ticket_link')->updateOrInsert(
            ['ticket_id' => $ticket->ticket_id, 'entity_type' => $entityType, 'entity_ref' => $entityRef, 'relation' => $relation],
            ['link_id' => $id, 'linked_by' => $actor, 'updated_at' => now(), 'created_at' => now()],
        );
        $this->timeline($ticket, 'LINKED', null, $ticket->status, $actor, ['entityType' => $entityType, 'entityRef' => $entityRef, 'relation' => $relation]);

        return DB::table('ticket_link')->where('ticket_id', $ticket->ticket_id)->where('entity_type', $entityType)->where('entity_ref', $entityRef)->where('relation', $relation)->first();
    }

    public function assign(Ticket $ticket, string $assigneeId, ?string $actor = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $assigneeId, $actor) {
            $from = $ticket->status;
            $ticket->update(['assignee_id' => $assigneeId, 'status' => Ticket::ASSIGNED]);
            $this->timeline($ticket, 'ASSIGNED', $from, Ticket::ASSIGNED, $actor, ['assigneeId' => $assigneeId]);
            $this->emit(TicketEvents::ASSIGNED, $ticket, ['assigneeId' => $assigneeId]);

            return $ticket;
        });
    }

    /** @param array<string,mixed> $data */
    public function comment(Ticket $ticket, array $data): Ticket
    {
        $ticket->comments()->create($data);
        $this->timeline($ticket, 'COMMENT', $ticket->status, $ticket->status, $data['author_id'] ?? null);

        return $ticket->load('comments');
    }

    /**
     * Raise a work order for field intervention and move the ticket to PENDING_WO.
     *
     * @param  array<string,mixed>  $woData
     */
    public function createWorkOrder(Ticket $ticket, array $woData, ?string $actor = null): Ticket
    {
        // TCK-7: terminal tickets cannot create WOs.
        if (in_array($ticket->status, [Ticket::RESOLVED, Ticket::CLOSED, Ticket::CANCELLED], true)) {
            throw new DomainException('TICKET_TERMINAL_STATE', 'A terminal ticket cannot create a work order.', 409);
        }
        // TCK-3: only configured categories with wo_allowed may create a WO.
        $category = TicketCategory::resolve($ticket->operator_code, $ticket->category);
        if (! $category || ! $category->wo_allowed) {
            throw new DomainException('TICKET_CATEGORY_WO_NOT_ALLOWED', "Category {$ticket->category} is not allowed to create a work order.", 409);
        }
        // One active linked WO at a time (TCK §9.1).
        if ($ticket->work_order_id && $ticket->status === Ticket::PENDING_WO) {
            throw new DomainException('ACTIVE_WORK_ORDER_ALREADY_LINKED', 'An active work order is already linked to this ticket.', 409);
        }

        return DB::transaction(function () use ($ticket, $woData, $actor, $category) {
            $wo = $this->workOrders->create(array_merge([
                'kind' => $category->default_wo_kind ?? 'SUPPORT',
                'type' => 'SUPPORT',
                'account_id' => $ticket->account_id,
                'customer_id' => $ticket->customer_id,
                'subscription_id' => $ticket->subscription_id,
                'source_type' => 'TICKET',
                'source_ref' => $ticket->ticket_id,
                'created_by' => $actor,
            ], $woData));

            $from = $ticket->status;
            $ticket->update(['work_order_id' => $wo->work_order_id, 'status' => Ticket::PENDING_WO]);
            $this->timeline($ticket, 'WORK_ORDER_LINKED', $from, Ticket::PENDING_WO, $actor, ['workOrderId' => $wo->work_order_id]);
            $this->emit(TicketEvents::WORK_ORDER_LINKED, $ticket, ['workOrderId' => $wo->work_order_id]);

            return $ticket->refresh();
        });
    }

    /** @param array<string,mixed> $data */
    public function resolve(Ticket $ticket, array $data, ?string $actor = null): Ticket
    {
        if ($ticket->status === Ticket::CLOSED) {
            throw DomainException::conflict('Ticket is already closed.');
        }

        return DB::transaction(function () use ($ticket, $data, $actor) {
            $from = $ticket->status;
            $ticket->update([
                'status' => Ticket::RESOLVED,
                'resolution_code' => $data['resolution_code'] ?? null,
                'resolution_note' => $data['resolution_note'] ?? null,
                'resolved_at' => now(),
            ]);
            $this->timeline($ticket, 'RESOLVED', $from, Ticket::RESOLVED, $actor);
            $this->emit(TicketEvents::RESOLVED, $ticket, ['resolutionCode' => $ticket->resolution_code]);

            return $ticket;
        });
    }

    /**
     * TCK-01 §9.2: a linked Work Order finished → move the ticket out of PENDING_WO.
     * Records the timeline event and resolves (or, if review is required, parks in
     * UNDER_REVIEW). Idempotent: only acts while the ticket waits on that WO.
     */
    public function onWorkOrderFinalized(string $workOrderId, ?string $finalReason = null): void
    {
        $ticket = Ticket::query()->where('work_order_id', $workOrderId)->where('status', Ticket::PENDING_WO)->first();
        if (! $ticket) {
            return;
        }
        DB::transaction(function () use ($ticket, $workOrderId, $finalReason) {
            $from = $ticket->status;
            $ticket->update(['status' => Ticket::RESOLVED, 'resolution_code' => $finalReason, 'resolved_at' => now()]);
            $this->timeline($ticket, 'LINKED_WORK_ORDER_FINALIZED', $from, Ticket::RESOLVED, null, ['workOrderId' => $workOrderId, 'finalReason' => $finalReason]);
            $this->emit(TicketEvents::RESOLVED, $ticket, ['resolutionCode' => $finalReason, 'via' => 'WORK_ORDER']);
        });
    }

    /** TCK-01 §8.8 reopen a RESOLVED ticket (RESOLVED → OPEN only, via reopen policy). */
    public function reopen(Ticket $ticket, string $reasonCode, ?string $comment = null, ?string $actor = null): Ticket
    {
        if ($ticket->status !== Ticket::RESOLVED) {
            throw new DomainException('TICKET_TERMINAL_STATE', 'Only a RESOLVED ticket can be reopened.', 409);
        }

        return DB::transaction(function () use ($ticket, $reasonCode, $comment, $actor) {
            $ticket->update([
                'status' => Ticket::OPEN,
                'reopened_count' => (int) $ticket->reopened_count + 1,
                'resolved_at' => null,
            ]);
            $this->timeline($ticket, 'REOPENED', Ticket::RESOLVED, Ticket::OPEN, $actor, ['reasonCode' => $reasonCode, 'comment' => $comment]);
            $this->emit(TicketEvents::REOPENED, $ticket, ['reasonCode' => $reasonCode, 'reopenedCount' => $ticket->reopened_count]);

            return $ticket->refresh();
        });
    }

    /** TCK-01 §5 cancel a non-terminal ticket (duplicate / error / customer withdrew). */
    public function cancel(Ticket $ticket, ?string $reason = null, ?string $actor = null): Ticket
    {
        if (in_array($ticket->status, [Ticket::RESOLVED, Ticket::CLOSED, Ticket::CANCELLED], true)) {
            throw new DomainException('TICKET_TERMINAL_STATE', 'Ticket is already terminal.', 409);
        }

        return DB::transaction(function () use ($ticket, $reason, $actor) {
            $from = $ticket->status;
            $ticket->update(['status' => Ticket::CANCELLED, 'cancelled_at' => now()]);
            $this->timeline($ticket, 'CANCELLED', $from, Ticket::CANCELLED, $actor, ['reason' => $reason]);
            $this->emit(TicketEvents::CANCELLED, $ticket, ['reason' => $reason]);

            return $ticket->refresh();
        });
    }

    public function close(Ticket $ticket, ?string $actor = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $actor) {
            $from = $ticket->status;
            $ticket->update(['status' => Ticket::CLOSED, 'closed_at' => now()]);
            $this->timeline($ticket, 'CLOSED', $from, Ticket::CLOSED, $actor);
            $this->emit(TicketEvents::CLOSED, $ticket, []);

            return $ticket;
        });
    }

    /** @param array<string,mixed> $meta */
    private function timeline(Ticket $ticket, string $type, ?string $from, ?string $to, ?string $actor, array $meta = []): void
    {
        $ticket->timeline()->create([
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function emit(string $type, Ticket $ticket, array $payload): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: TicketEvents::TOPIC,
            payload: array_merge(['ticketId' => $ticket->ticket_id, 'status' => $ticket->status], $payload),
            aggregateType: 'Ticket',
            aggregateId: $ticket->ticket_id,
        ));
    }
}
