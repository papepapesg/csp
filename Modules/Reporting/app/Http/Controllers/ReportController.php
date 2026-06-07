<?php

namespace Modules\Reporting\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Reporting\Models\ReportDailyMetric;
use Modules\Reporting\Services\ReportExportService;
use Modules\Reporting\Services\ReportReconciliationService;
use Symfony\Component\HttpFoundation\Response;

/**
 * REP-01 dashboard + metric read API. Reads only from the reporting mart.
 */
class ReportController extends ApiController
{
    /** Dashboard code -> metric keys included. */
    private const DASHBOARDS = [
        'operations-overview' => [
            'orders_captured', 'orders_completed', 'subscriptions_created', 'subscriptions_activated',
            'work_orders_finalized', 'tickets_created', 'tickets_resolved',
        ],
        'revenue-overview' => [
            'invoices_generated', 'invoices_amount', 'invoices_paid', 'payments_count', 'payments_amount', 'wallet_topups_amount',
        ],
    ];

    /** GET /api/reports/dashboards/{code}?from=&to= */
    public function dashboard(Request $request, string $code): JsonResponse
    {
        $keys = self::DASHBOARDS[$code] ?? null;
        if ($keys === null) {
            return ApiResponse::error('NOT_FOUND', "Unknown dashboard [{$code}].", 404);
        }

        [$from, $to] = $this->range($request);

        $totals = ReportDailyMetric::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->whereIn('metric_key', $keys)
            ->whereDate('metric_date', '>=', $from)
            ->whereDate('metric_date', '<=', $to)
            ->selectRaw('metric_key, SUM(value) as total')
            ->groupBy('metric_key')
            ->pluck('total', 'metric_key');

        $cards = [];
        foreach ($keys as $key) {
            $cards[$key] = (float) ($totals[$key] ?? 0);
        }

        return ApiResponse::item([
            'dashboard' => $code,
            'from' => $from,
            'to' => $to,
            'metrics' => $cards,
        ]);
    }

    /** GET /api/reports/metrics?key=&from=&to= (time series) */
    public function metrics(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $series = ReportDailyMetric::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('key'), fn ($q, $k) => $q->whereIn('metric_key', explode(',', $k)))
            ->whereDate('metric_date', '>=', $from)
            ->whereDate('metric_date', '<=', $to)
            ->orderBy('metric_date')
            ->get(['metric_date', 'metric_key', 'value']);

        return ApiResponse::item(['items' => $series]);
    }

    /** GET /api/reports/export/{code}.csv?from=&to= — CSV export of a dashboard's metrics. */
    public function export(Request $request, string $code, ReportExportService $exporter): Response
    {
        $keys = self::DASHBOARDS[$code] ?? null;
        if ($keys === null) {
            return ApiResponse::error('NOT_FOUND', "Unknown dashboard [{$code}].", 404);
        }
        [$from, $to] = $this->range($request);
        $operator = $request->query('operatorCode', Context::operatorCode());
        $csv = $exporter->csv($operator, $keys, $from, $to);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$code}-{$from}-to-{$to}.csv\"",
        ]);
    }

    /** GET /api/reports/reconcile?from=&to= — mart-vs-event-log integrity check. */
    public function reconcile(Request $request, ReportReconciliationService $reconciler): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $operator = $request->query('operatorCode', Context::operatorCode());
        $report = $reconciler->reconcile($operator, $from, $to);

        return ApiResponse::item([
            'operatorCode' => $operator,
            'from' => $from,
            'to' => $to,
            'checked' => $report['checked'],
            'inSync' => $report['discrepancies'] === [],
            'discrepancies' => $report['discrepancies'],
        ]);
    }

    /** @return array{0:string,1:string} */
    private function range(Request $request): array
    {
        $from = $request->query('from', now()->subDays(30)->toDateString());
        $to = $request->query('to', now()->toDateString());

        return [$from, $to];
    }
}
