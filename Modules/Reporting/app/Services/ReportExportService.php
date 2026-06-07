<?php

namespace Modules\Reporting\Services;

use Modules\Reporting\Models\ReportDailyMetric;

/**
 * REP-01 metric export. Streams the daily-metric mart as CSV for a set of metric
 * keys + date window (offline analysis / finance reconciliation).
 */
class ReportExportService
{
    /**
     * @param  array<int,string>  $keys
     */
    public function csv(string $operator, array $keys, string $from, string $to): string
    {
        $rows = ReportDailyMetric::query()
            ->where('operator_code', $operator)
            ->when($keys, fn ($q) => $q->whereIn('metric_key', $keys))
            ->whereDate('metric_date', '>=', $from)
            ->whereDate('metric_date', '<=', $to)
            ->orderBy('metric_date')
            ->orderBy('metric_key')
            ->get(['metric_date', 'metric_key', 'value']);

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['operator_code', 'metric_date', 'metric_key', 'value']);
        foreach ($rows as $row) {
            fputcsv($out, [$operator, $row->metric_date->toDateString(), $row->metric_key, (float) $row->value]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
