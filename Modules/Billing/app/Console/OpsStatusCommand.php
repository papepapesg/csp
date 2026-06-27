<?php

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Adjustments\Models\AdjustmentRequest;
use Modules\Billing\Dunning\Models\DunningState;
use Modules\Billing\Invoicing\Models\Invoice;
use Modules\Billing\Tax\Models\TaxInvoice;

/**
 * Ops review: a one-glance health summary of the billing work queues an operator
 * needs to drain (read-only). Mirrors the §12 runbook inventory queries.
 */
class OpsStatusCommand extends Command
{
    protected $signature = 'sophix:billing:ops-status {--operator= : Scope to one operator code (default: all)}';

    protected $description = 'Review: counts of billing items needing ops attention (read-only)';

    public function handle(): int
    {
        $op = $this->option('operator');
        $scope = fn ($q) => $op ? $q->where('operator_code', $op) : $q;

        $overdue = $scope(Invoice::query())
            ->whereIn('status', [Invoice::OPEN, Invoice::PARTIALLY_PAID, Invoice::OVERDUE])
            ->where('amount_due', '>', 0)->where('due_date', '<', now())->count();

        $rows = [
            ['Overdue invoices (debt > 0, past due)', $overdue],
            ['Dunning: pending termination review', $scope(DunningState::query())->where('status', DunningState::STATUS_PENDING_TERMINATION_REVIEW)->count()],
            ['Dunning: recovery failed', $scope(DunningState::query())->where('status', DunningState::STATUS_RECOVERY_FAILED)->count()],
            ['Adjustments awaiting approval', $scope(AdjustmentRequest::query())->whereIn('status', [AdjustmentRequest::PENDING_APPROVAL, AdjustmentRequest::PROPOSED])->count()],
            ['Adjustments: application failed', $scope(AdjustmentRequest::query())->where('status', AdjustmentRequest::APPLICATION_FAILED)->count()],
            ['Bulk reversals awaiting approval', $scope(DB::table('bulk_reversal_batch'))->where('status', 'PENDING_APPROVAL')->count()],
            ['Tax invoices: signing failed', $scope(TaxInvoice::query())->where('status', 'SIGNING_FAILED')->count()],
            ['Invoice-generation failures (pending retry)', $scope(DB::table('generation_failure_queue'))->where('status', 'PENDING_RETRY')->count()],
        ];

        $this->info('Billing ops status'.($op ? " — operator {$op}" : ' — all operators'));
        $this->table(['Queue', 'Count'], $rows);
        $this->line('Drain hints: dunning → sophix:billing:dunning-fix · tax → sophix:billing:tax-retry-scan · gen → sophix:billing:generation-retry');

        return self::SUCCESS;
    }
}
