<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Intent\Http\Controllers\BillableEventController;

/*
| BIL-CFG-01 BillableEvent catalog (admin) — the operator-governed template set
| of what BIL-01 intents may charge. Owned by the Intent module: the catalog's
| runtime consumer and enforcer is BillingIntentService.
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('billing/billable-events', [BillableEventController::class, 'index'])->middleware('permission:catalog.read');
    Route::get('billing/billable-event-categories', [BillableEventController::class, 'categories'])->middleware('permission:catalog.read');
    Route::get('billing/billable-events/{billableEvent}', [BillableEventController::class, 'show'])->middleware('permission:catalog.read');
    Route::post('billing/billable-events', [BillableEventController::class, 'store'])->middleware(['permission:catalog.manage', 'idempotency']);
    Route::patch('billing/billable-events/{billableEvent}', [BillableEventController::class, 'update'])->middleware('permission:catalog.manage');
    Route::post('billing/billable-events/{billableEvent}/activate', [BillableEventController::class, 'activate'])->middleware('permission:catalog.manage');
    Route::post('billing/billable-events/{billableEvent}/retire', [BillableEventController::class, 'retire'])->middleware('permission:catalog.manage');
});
