<?php

use Illuminate\Support\Facades\Route;
use Modules\Subscription\Http\Controllers\OperationController;
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

    // SUB-WF operation tracking
    Route::get('subscriptions/{subscription}/operations', [OperationController::class, 'index'])->middleware('permission:subscription.read');
    Route::get('subscriptions/{subscription}/operations/{operation}', [OperationController::class, 'show'])->middleware('permission:subscription.read');
});
