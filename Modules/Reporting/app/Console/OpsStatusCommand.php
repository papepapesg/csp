<?php

namespace Modules\Reporting\Console;

use Illuminate\Console\Command;
use Modules\Reporting\Models\ReportDailyMetric;

/**
 * Ops review: a one-glance inventory/health summary of the REP-01 daily-metric mart
 * an operator can use to confirm the read model is being fed and is fresh (read-only).
 * REP owns only this read-through mart, so a mart inventory is the module's ops surface.
 */
class OpsStatusCommand extends Command
{
    protected $signature = 'sophix:reporting:ops-status {--operator= : Scope to one operator code (default: all)}';

    protected $description = 'Review: report-mart inventory & freshness (read-only)';

    public function handle(): int
    {
        $op = $this->option('operator');
        $scope = fn ($q) => $op ? $q->where('operator_code', $op) : $q;

        $rows = $scope(ReportDailyMetric::query())->count();
        $operators = ReportDailyMetric::query()->distinct()->count('operator_code');
        $keys = $scope(ReportDailyMetric::query())->distinct()->count('metric_key');
        $latest = $scope(ReportDailyMetric::query())->max('metric_date');
        $earliest = $scope(ReportDailyMetric::query())->min('metric_date');

        $this->info('Reporting mart status'.($op ? " — operator {$op}" : ' — all operators'));
        $this->table(['Field', 'Value'], [
            ['report_daily_metric rows', $rows],
            ['Distinct operators (all)', $operators],
            ['Distinct metric keys'.($op ? " (operator {$op})" : ''), $keys],
            ['Earliest metric_date', $earliest ?: '—'],
            ['Latest metric_date', $latest ?: '—'],
        ]);

        if ($latest === null) {
            $this->warn('Mart is empty for this scope — projector may not have consumed any events yet.');
        } elseif ($latest < now()->subDays(2)->toDateString()) {
            $this->warn("Latest metric_date is {$latest} (> 2 days old) — check the reporting.metrics projector.");
        }

        return self::SUCCESS;
    }
}
