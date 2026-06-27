<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Mediation\Http\Controllers\BillableEventController;
use Modules\Billing\Mediation\Http\Controllers\UsageController;

/*
| BIL-CFG-01 BillableEvent catalog + MED-01/RAT-01 usage mediation & rating API.
*/
Route::middleware('auth:sanctum')->group(function () {
    // BIL-CFG-01 — BillableEvent catalog (admin)
    Route::get('billing/billable-events', [BillableEventController::class, 'index'])->middleware('permission:catalog.read');
    Route::get('billing/billable-event-categories', [BillableEventController::class, 'categories'])->middleware('permission:catalog.read');
    Route::get('billing/billable-events/{billableEvent}', [BillableEventController::class, 'show'])->middleware('permission:catalog.read');
    Route::post('billing/billable-events', [BillableEventController::class, 'store'])->middleware(['permission:catalog.manage', 'idempotency']);
    Route::patch('billing/billable-events/{billableEvent}', [BillableEventController::class, 'update'])->middleware('permission:catalog.manage');
    Route::post('billing/billable-events/{billableEvent}/activate', [BillableEventController::class, 'activate'])->middleware('permission:catalog.manage');
    Route::post('billing/billable-events/{billableEvent}/retire', [BillableEventController::class, 'retire'])->middleware('permission:catalog.manage');

    // MED-01 mediation + RAT-01 rating
    Route::get('usage', [UsageController::class, 'index'])->middleware('permission:invoice.read');
    Route::post('usage', [UsageController::class, 'ingest'])->middleware(['permission:invoice.manage', 'idempotency']);
    Route::post('usage/rate-run', [UsageController::class, 'rateRun'])->middleware('permission:invoice.manage');
    Route::get('rated-events', [UsageController::class, 'ratedEvents'])->middleware('permission:invoice.read');
});
