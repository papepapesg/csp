<?php

namespace Modules\Reporting\Console;

use Illuminate\Console\Command;
use Modules\Reporting\Services\ReportReconciliationService;

/**
 * Ops review: inspect one operator+window's mart-integrity state by running the
 * EXISTING ReportReconciliationService (the same check behind GET /api/reports/reconcile).
 * Re-projects the outbox event log and diffs it against the stored mart — read-only,
 * it reports discrepancies but never writes (HLD §6.6 reporting-integrity check).
 */
class ReconcileShowCommand extends Command
{
    protected $signature = 'sophix:reporting:reconcile-show
        {operator : The operator_code}
        {--from= : Window start (Y-m-d, default 30 days ago)}
        {--to= : Window end (Y-m-d, default today)}';

    protected $description = 'Review: mart-vs-event-log reconciliation for one operator (read-only)';

    public function handle(ReportReconciliationService $reconciler): int
    {
        $operator = (string) $this->argument('operator');
        $from = (string) ($this->option('from') ?: now()->subDays(30)->toDateString());
        $to = (string) ($this->option('to') ?: now()->toDateString());

        $report = $reconciler->reconcile($operator, $from, $to);

        $this->info("Reconcile {$operator} — {$from} .. {$to} (checked {$report['checked']} metric cells)");

        if ($report['discrepancies'] === []) {
            $this->info('In sync — no discrepancies.');

            return self::SUCCESS;
        }

        $this->warn(count($report['discrepancies']).' discrepancy(ies) — mart diverges from the event log:');
        $this->table(
            ['date', 'metric', 'expected', 'actual', 'delta'],
            array_map(fn ($d) => [$d['date'], $d['metric'], $d['expected'], $d['actual'], $d['delta']], $report['discrepancies']),
        );

        return self::SUCCESS;
    }
}
