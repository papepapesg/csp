<?php

namespace Modules\Ticketing\Console;

use Illuminate\Console\Command;
use Modules\Ticketing\Models\Ticket;

/**
 * Ops review: a one-glance health summary of the ticketing work queues an operator
 * needs to drain (read-only) — SLA breaches, the first-response clock, sign-off
 * backlog (UNDER_REVIEW / requires_review) and unassigned/stuck cases.
 */
class OpsStatusCommand extends Command
{
    protected $signature = 'sophix:ticketing:ops-status {--operator= : Scope to one operator code (default: all)}';

    protected $description = 'Review: counts of tickets needing ops attention (read-only)';

    /** Non-terminal statuses: a ticket in any of these is still live work. */
    private const OPEN_STATES = [
        Ticket::OPEN, Ticket::TRIAGED, Ticket::ASSIGNED, Ticket::IN_PROGRESS,
        Ticket::WAITING_CUSTOMER, Ticket::WAITING_INTERNAL, Ticket::WAITING_WORK_ORDER,
        Ticket::PENDING_WO, Ticket::UNDER_REVIEW,
    ];

    public function handle(): int
    {
        $op = $this->option('operator');
        $scope = fn () => $op ? Ticket::query()->where('operator_code', $op) : Ticket::query();

        $rows = [
            ['SLA-breached (resolution clock past due, still open)',
                $scope()->whereIn('status', self::OPEN_STATES)->where('sla_due_at', '<', now())->count()],
            ['First-response breached (no agent response, clock past due)',
                $scope()->whereIn('status', self::OPEN_STATES)->whereNull('first_response_at')->where('first_response_due_at', '<', now())->count()],
            ['Unassigned (open, no owner)',
                $scope()->whereIn('status', self::OPEN_STATES)->whereNull('assignee_id')->count()],
            ['Awaiting sign-off (UNDER_REVIEW)',
                $scope()->where('status', Ticket::UNDER_REVIEW)->count()],
            ['Flagged for review (requires_review)',
                $scope()->where('requires_review', true)->whereIn('status', self::OPEN_STATES)->count()],
            ['Waiting on work order (WAITING_WORK_ORDER / PENDING_WO)',
                $scope()->whereIn('status', [Ticket::WAITING_WORK_ORDER, Ticket::PENDING_WO])->count()],
            ['Reopened at least once (open)',
                $scope()->whereIn('status', self::OPEN_STATES)->where('reopened_count', '>', 0)->count()],
        ];

        $this->info('Ticketing ops status'.($op ? " — operator {$op}" : ' — all operators'));
        $this->table(['Queue', 'Count'], $rows);
        $this->line('Inspect one ticket: sophix:ticketing:ticket-show <ticket> · reassign: sophix:ticketing:ticket-reassign <ticket> <assignee>');

        return self::SUCCESS;
    }
}
