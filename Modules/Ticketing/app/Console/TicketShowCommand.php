<?php

namespace Modules\Ticketing\Console;

use Illuminate\Console\Command;
use Modules\Ticketing\Models\Ticket;

/**
 * Ops review: show one ticket's live state and its append-only timeline (read-only) —
 * status, owner, queue, the SLA / first-response clocks and review flags, so support
 * can see why a case is (or isn't) progressing before touching anything.
 */
class TicketShowCommand extends Command
{
    protected $signature = 'sophix:ticketing:ticket-show
        {ticket : The ticket_id or human-facing ticket_number}
        {--timeline=20 : How many recent timeline events to show}';

    protected $description = 'Review: show a ticket\'s state and timeline (read-only)';

    public function handle(): int
    {
        $ref = (string) $this->argument('ticket');
        $ticket = Ticket::query()
            ->where('ticket_id', $ref)
            ->orWhere('ticket_number', $ref)
            ->first();

        if (! $ticket) {
            $this->warn("No ticket found for '{$ref}' (by ticket_id or ticket_number).");

            return self::SUCCESS;
        }

        $this->table(['Field', 'Value'], [
            ['ticket_id', $ticket->ticket_id],
            ['ticket_number', (string) $ticket->ticket_number],
            ['operator_code', $ticket->operator_code],
            ['status', $ticket->status],
            ['category', $ticket->category],
            ['asr_type', (string) $ticket->asr_type],
            ['priority', $ticket->priority],
            ['queue', (string) $ticket->queue],
            ['assignee_id', (string) ($ticket->assignee_id ?? '—')],
            ['subject', (string) $ticket->subject],
            ['sla_due_at', (string) $ticket->sla_due_at],
            ['first_response_due_at', (string) $ticket->first_response_due_at],
            ['first_response_at', (string) ($ticket->first_response_at ?? '—')],
            ['requires_review', $ticket->requires_review ? 'yes' : 'no'],
            ['reopened_count', (string) $ticket->reopened_count],
            ['work_order_id', (string) ($ticket->work_order_id ?? '—')],
            ['resolution_code', (string) ($ticket->resolution_code ?? '—')],
            ['resolved_at', (string) ($ticket->resolved_at ?? '—')],
            ['closed_at', (string) ($ticket->closed_at ?? '—')],
            ['cancelled_at', (string) ($ticket->cancelled_at ?? '—')],
        ]);

        if ($ticket->status === Ticket::UNDER_REVIEW) {
            $this->warn('Status UNDER_REVIEW — awaiting supervisor sign-off (§9.2).');
        }
        if ($ticket->requires_review) {
            $this->warn('requires_review flag set — needs review before it can resolve.');
        }

        $limit = max(1, (int) $this->option('timeline'));
        $events = $ticket->timeline()->orderByDesc('created_at')->limit($limit)->get();
        $this->info("Timeline (latest {$events->count()}):");
        $this->table(
            ['created_at', 'event_type', 'from', 'to', 'actor_id'],
            $events->map(fn ($e) => [
                (string) $e->created_at,
                $e->event_type,
                (string) ($e->from_status ?? '—'),
                (string) ($e->to_status ?? '—'),
                (string) ($e->actor_id ?? '—'),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
