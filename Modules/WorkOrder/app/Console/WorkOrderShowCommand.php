<?php

namespace Modules\WorkOrder\Console;

use Illuminate\Console\Command;
use Modules\WorkOrder\Models\WorkOrder;

/**
 * Ops review: show one work order's live state, assignment, SLA clock and
 * status history (read-only) — so support can see where a WO is stuck and why
 * before touching anything.
 */
class WorkOrderShowCommand extends Command
{
    protected $signature = 'sophix:workorder:show {work_order : The work_order_id}';

    protected $description = 'Review: show a work order\'s state, assignment and history (read-only)';

    public function handle(): int
    {
        $id = (string) $this->argument('work_order');
        $wo = WorkOrder::query()->where('work_order_id', $id)->first();
        if (! $wo) {
            $this->warn("No work order {$id}.");

            return self::SUCCESS;
        }

        $this->table(['Field', 'Value'], [
            ['work_order_id', $wo->work_order_id],
            ['operator_code', $wo->operator_code],
            ['type / kind', $wo->type.' / '.($wo->kind ?? '—')],
            ['job_type_code', $wo->job_type_code ?? '—'],
            ['status', $wo->status],
            ['current_phase', $wo->current_phase ?? '—'],
            ['priority', $wo->priority],
            ['account_id', $wo->account_id ?? '—'],
            ['subscription_id', $wo->subscription_id ?? '—'],
            ['tech_region_id', $wo->tech_region_id ?? '—'],
            ['contractor_id', $wo->contractor_id ?? '—'],
            ['team_id', $wo->team_id ?? '—'],
            ['assigned_technician_id', $wo->assigned_technician_id ?? '—'],
            ['master_wo_id', $wo->master_wo_id ?? '—'],
            ['required_skills', implode(', ', (array) ($wo->required_skills ?? [])) ?: '—'],
            ['scheduled_at', (string) $wo->scheduled_at],
            ['sla_due_at', (string) $wo->sla_due_at],
            ['first_response_at', (string) $wo->first_response_at],
            ['started_at', (string) $wo->started_at],
            ['finalized_at', (string) $wo->finalized_at],
            ['resolution_code', $wo->resolution_code ?? '—'],
            ['final_reason', $wo->final_reason ?? '—'],
            ['escalation_candidate', $wo->escalation_candidate ? 'yes' : 'no'],
        ]);

        if ($wo->sla_due_at && now()->greaterThan($wo->sla_due_at) && ! in_array($wo->status, [WorkOrder::COMPLETED, WorkOrder::CANCELLED], true)) {
            $this->warn('SLA breached — sla_due_at is in the past and the WO is still open.');
        }

        $history = $wo->statusHistory()->orderBy('changed_at')->get();
        if ($history->isNotEmpty()) {
            $this->info('Status history');
            $this->table(
                ['changed_at', 'prev', 'new', 'reason', 'changed_by'],
                $history->map(fn ($h) => [
                    (string) $h->changed_at, $h->prev_status ?? '—', $h->new_status, $h->reason ?? '—', $h->changed_by ?? '—',
                ])->all(),
            );
        }

        return self::SUCCESS;
    }
}
