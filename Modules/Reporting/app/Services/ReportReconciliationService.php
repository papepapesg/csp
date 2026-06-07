<?php

namespace Modules\Reporting\Services;

use App\Foundation\Events\Outbox\OutboxEvent;
use Modules\Reporting\Models\ReportDailyMetric;
use Modules\Reporting\Support\MetricMap;

/**
 * REP-01 mart reconciliation. Re-projects the authoritative outbox event log with
 * the same MetricMap the live projector uses, then diffs the expected totals
 * against the stored daily-metric mart. Any divergence means the projector missed
 * or double-counted an event (a reporting-integrity check, staying within the
 * event/mart boundary — never the operational tables).
 */
class ReportReconciliationService
{
    /**
     * @return array{checked:int, discrepancies:array<int,array<string,mixed>>}
     */
    public function reconcile(string $operator, string $from, string $to): array
    {
        // Expected: re-project the published outbox events in the window.
        $expected = [];
        OutboxEvent::query()
            ->where('operator_code', $operator)
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->orderBy('id')
            ->chunk(500, function ($events) use (&$expected) {
                foreach ($events as $event) {
                    $date = $event->created_at->toDateString();
                    foreach (MetricMap::for($event->event_type, $event->payload ?? []) as [$key, $delta]) {
                        $expected[$date][$key] = ($expected[$date][$key] ?? 0) + $delta;
                    }
                }
            });

        // Actual: the stored mart.
        $actual = [];
        ReportDailyMetric::query()
            ->where('operator_code', $operator)
            ->whereDate('metric_date', '>=', $from)
            ->whereDate('metric_date', '<=', $to)
            ->get(['metric_date', 'metric_key', 'value'])
            ->each(function ($row) use (&$actual) {
                $actual[$row->metric_date->toDateString()][$row->metric_key] = (float) $row->value;
            });

        // Diff (union of keys).
        $discrepancies = [];
        $checked = 0;
        $dates = array_unique([...array_keys($expected), ...array_keys($actual)]);
        foreach ($dates as $date) {
            $keys = array_unique([...array_keys($expected[$date] ?? []), ...array_keys($actual[$date] ?? [])]);
            foreach ($keys as $key) {
                $checked++;
                $exp = (float) ($expected[$date][$key] ?? 0);
                $act = (float) ($actual[$date][$key] ?? 0);
                if (abs($exp - $act) > 0.0001) {
                    $discrepancies[] = ['date' => $date, 'metric' => $key, 'expected' => $exp, 'actual' => $act, 'delta' => round($act - $exp, 2)];
                }
            }
        }

        return ['checked' => $checked, 'discrepancies' => $discrepancies];
    }
}
