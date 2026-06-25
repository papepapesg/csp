<?php

namespace Modules\Osr\Console;

use App\Foundation\Approvals\ApprovalRequest;
use Illuminate\Console\Command;
use Modules\Osr\Models\StockReservation;

/**
 * Ops review: a one-glance health summary of the OSR-01 stock chain an operator
 * needs to keep clean (read-only) — overdue reservation expiries the sweep will
 * pick up, and stock-movement approvals sitting PENDING in the EM-CFG-04 engine.
 * Listing only: approving/executing a held movement is NOT done from the CLI.
 */
class OpsStatusCommand extends Command
{
    protected $signature = 'sophix:stock:ops-status {--operator= : Scope to one operator code (default: all)}';

    protected $description = 'Review: counts of OSR stock items needing ops attention (read-only)';

    public function handle(): int
    {
        $op = $this->option('operator');
        $scope = fn ($q) => $op ? $q->where('operator_code', $op) : $q;

        $rows = [
            ['Reservations ACTIVE & past expires_at (sweep will release)', $scope(StockReservation::query())
                ->where('status', StockReservation::ACTIVE)
                ->whereNotNull('expires_at')->where('expires_at', '<', now())->count()],
            ['Reservations ACTIVE & expiring within 7 days', $scope(StockReservation::query())
                ->where('status', StockReservation::ACTIVE)
                ->whereNotNull('expires_at')->whereBetween('expires_at', [now(), now()->addDays(7)])->count()],
            ['Stock-movement approvals PENDING (EM-CFG-04)', $scope(ApprovalRequest::query())
                ->where('entity_type', 'STOCK_MOVEMENT')->where('status', ApprovalRequest::PENDING)->count()],
        ];

        $this->info('OSR stock ops status'.($op ? " — operator {$op}" : ' — all operators'));
        $this->table(['Queue', 'Count'], $rows);
        $this->line('Drain hints: expiries → sophix:stock:expire-reservations · pending approvals are decided in the EM-CFG-04 approvals app (not the CLI) · inspect → sophix:stock:show');

        return self::SUCCESS;
    }
}
