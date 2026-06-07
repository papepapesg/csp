<?php

namespace Modules\Ticketing\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Illuminate\Support\Facades\DB;
use Modules\Ticketing\Events\TicketEvents;
use Modules\Ticketing\Models\Ticket;
use Modules\WorkOrder\Services\WorkOrderService;

/**
 * TCK-01 case lifecycle. Owns ticket status, assignment, comments and timeline.
 * When field intervention is needed it creates a WO (WO owns field execution)
 * and waits for WorkOrderFinalized before resolving (HLD §6.7).
 */
class TicketService
{
    /** SLA hours by priority. */
    private const SLA_HOURS = ['URGENT' => 4, 'HIGH' => 8, 'NORMAL' => 24, 'LOW' => 72];

    public function __construct(
        private readonly EventBus $events,
        private readonly WorkOrderService $workOrders,
    ) {}

    /** @param array<string,mixed> $data */
    public function create(array $data): Ticket
    {
        return DB::transaction(function () use ($data) {
            $priority = $data['priority'] ?? 'NORMAL';
            $ticket = Ticket::query()->create($data + [
                'status' => Ticket::OPEN,
                'sla_due_at' => now()->addHours(self::SLA_HOURS[$priority] ?? 24),
            ]);
            $this->timeline($ticket, 'CREATED', null, Ticket::OPEN, $data['opened_by'] ?? null);
            $this->emit(TicketEvents::CREATED, $ticket, ['category' => $ticket->category, 'priority' => $ticket->priority]);

            return $ticket;
        });
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
        return DB::transaction(function () use ($ticket, $woData, $actor) {
            $wo = $this->workOrders->create(array_merge([
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
