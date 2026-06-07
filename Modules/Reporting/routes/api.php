<?php

use Illuminate\Support\Facades\Route;
use Modules\Reporting\Http\Controllers\ReportController;

/*
| REP-01 reporting dashboard/metric API (DD_API-00). Reads: report.view.
| Reads only from the reporting mart, never operational modules.
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('reports/dashboards/{code}', [ReportController::class, 'dashboard'])->middleware('permission:report.view');
    Route::get('reports/metrics', [ReportController::class, 'metrics'])->middleware('permission:report.view');
});
