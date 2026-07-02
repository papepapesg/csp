<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Mediation\Http\Controllers\UsageController;

/*
| MED-01/RAT-01 usage mediation & rating API. (The BIL-CFG-01 BillableEvent
| catalog routes live with their owning module, BillingIntent.)
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('usage', [UsageController::class, 'index'])->middleware('permission:invoice.read');
    Route::post('usage', [UsageController::class, 'ingest'])->middleware(['permission:invoice.manage', 'idempotency']);
    Route::post('usage/rate-run', [UsageController::class, 'rateRun'])->middleware('permission:invoice.manage');
    Route::get('rated-events', [UsageController::class, 'ratedEvents'])->middleware('permission:invoice.read');
});
