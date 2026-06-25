<?php

namespace Modules\Ticketing\Console;

use Modules\Ticketing\Models\Ticket;
use Illuminate\Console\Command;
use Modules\Ticketing\Services\TicketService;

/**
 * Ops safe-correction: reassign one ticket to a new owner through the EXISTING
 * TicketService::assign() operation (the same path behind the assign API — no
 * approval gate). This mutates the ticket (sets assignee + status ASSIGNED and
 * stamps first response), so it requires --confirm. Wraps the service directly,
 * bypassing route permissions; intended for break-glass console use by ops.
 */
class TicketReassignCommand extends Command
{
    protected $signature = 'sophix:ticketing:ticket-reassign
        {ticket : The ticket_id or human-facing ticket_number}
        {assignee : The new assignee_id to own the ticket}
        {--actor=cli-ops : Recorded as the acting user on the timeline event}
        {--confirm : Required — this changes ticket ownership and status}';

    protected $description = 'Safe-correction: reassign a ticket to a new owner (wraps TicketService::assign)';

    public function handle(TicketService $tickets): int
    {
        $ref = (string) $this->argument('ticket');
        $assignee = (string) $this->argument('assignee');
        $actor = (string) $this->option('actor');

        $ticket = Ticket::query()
            ->where('ticket_id', $ref)
            ->orWhere('ticket_number', $ref)
            ->first();

        if (! $ticket) {
            $this->error("No ticket found for '{$ref}' (by ticket_id or ticket_number).");

            return self::FAILURE;
        }

        // Terminal tickets cannot be reassigned to live work.
        if (in_array($ticket->status, [Ticket::RESOLVED, Ticket::CLOSED, Ticket::CANCELLED], true)) {
            $this->error("Ticket {$ticket->ticket_id} is {$ticket->status} (terminal); refusing to reassign.");

            return self::FAILURE;
        }

        if (! $this->option('confirm')) {
            $this->warn("Would reassign {$ticket->ticket_id} from ".($ticket->assignee_id ?? '—')." to {$assignee} (status → ASSIGNED).");
            $this->error('This mutates the ticket; re-run with --confirm.');

            return self::FAILURE;
        }

        $tickets->assign($ticket, $assignee, $actor);

        $this->info("ticket-reassign: {$ticket->ticket_id} assigned to {$assignee} by {$actor}.");
        $this->call('sophix:ticketing:ticket-show', ['ticket' => $ticket->ticket_id]);

        return self::SUCCESS;
    }
}
