<?php

use Illuminate\Support\Facades\Route;
use Modules\Subscription\Http\Controllers\OperationController;
use Modules\Subscription\Http\Controllers\RestrictionController;
use Modules\Subscription\Http\Controllers\SubscriptionController;

/*
| SUB-LM-01 subscription master + SUB-WF operation API (DD_API-00).
| Master reads/writes: subscription.read / subscription.create.
| Lifecycle commands: subscription.activate / subscription.manage (idempotent).
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('subscriptions', [SubscriptionController::class, 'index'])->middleware('permission:subscription.read');
    Route::post('subscriptions', [SubscriptionController::class, 'store'])->middleware(['permission:subscription.create', 'idempotency']);
    Route::get('subscriptions/{subscription}', [SubscriptionController::class, 'show'])->middleware('permission:subscription.read');

    // SUB-WF operation triggers
    Route::post('subscriptions/{subscription}/activate', [OperationController::class, 'activate'])->middleware('permission:subscription.activate');
    Route::post('subscriptions/{subscription}/pause', [OperationController::class, 'pause'])->middleware('permission:subscription.manage');
    Route::post('subscriptions/{subscription}/resume', [OperationController::class, 'resume'])->middleware('permission:subscription.manage');
    Route::post('subscriptions/{subscription}/terminate', [OperationController::class, 'terminate'])->middleware('permission:subscription.manage');
    Route::post('subscriptions/{subscription}/upgrade', [OperationController::class, 'upgrade'])->middleware('permission:subscription.manage');
    Route::post('subscriptions/{subscription}/downgrade', [OperationController::class, 'downgrade'])->middleware('permission:subscription.manage');
    Route::post('subscriptions/{subscription}/relocate', [OperationController::class, 'relocate'])->middleware('permission:subscription.manage');
    Route::post('subscriptions/{subscription}/migrate', [OperationController::class, 'migrate'])->middleware('permission:subscription.manage');
    Route::post('subscriptions/{subscription}/suspend-np', [OperationController::class, 'suspendNp'])->middleware('permission:subscription.manage');

    // SUB-WF-RESTRICT-01 partial-service restriction sub-resource (ADD/REMOVE/LIST) + platform monitor
    Route::get('subscription-restrictions', [RestrictionController::class, 'monitor'])->middleware('permission:subscription.read');
    Route::get('subscriptions/{subscription}/restrictions', [RestrictionController::class, 'index'])->middleware('permission:subscription.read');
    Route::post('subscriptions/{subscription}/restrictions', [RestrictionController::class, 'store'])->middleware(['permission:subscription.manage', 'idempotency']);
    Route::delete('subscriptions/{subscription}/restrictions/{code}', [RestrictionController::class, 'destroy'])->middleware(['permission:subscription.manage', 'idempotency']);

    // SUB-WF operation tracking
    Route::get('subscriptions/{subscription}/operations', [OperationController::class, 'index'])->middleware('permission:subscription.read');
    Route::get('subscriptions/{subscription}/operations/{operation}', [OperationController::class, 'show'])->middleware('permission:subscription.read');
    Route::get('subscriptions/{subscription}/in-flight-operation', [OperationController::class, 'inFlight'])->middleware('permission:subscription.read');

    // SUB-WF-FRAMEWORK-01 cross-operation APIs (§8.2/8.3)
    Route::get('subscription-operations/{operation}', [OperationController::class, 'status'])->middleware('permission:subscription.read');
    Route::post('subscription-operations/{operation}/cancel', [OperationController::class, 'cancel'])->middleware('permission:subscription.manage');
});
