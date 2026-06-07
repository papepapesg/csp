<?php

use Illuminate\Support\Facades\Route;
use Modules\Notification\Http\Controllers\NotificationController;

/*
| NOT-01 notification + ICN-01 internal comms API (DD_API-00).
| Reads: notification.read; send: notification.send.
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('notifications', [NotificationController::class, 'index'])->middleware('permission:notification.read');
    Route::post('notifications', [NotificationController::class, 'send'])->middleware(['permission:notification.send', 'idempotency']);
    Route::get('notifications/{notification}', [NotificationController::class, 'show'])->middleware('permission:notification.read');

    Route::get('internal-messages', [NotificationController::class, 'internalIndex'])->middleware('permission:notification.read');
    Route::post('internal-messages', [NotificationController::class, 'postInternal'])->middleware('permission:notification.send');
});
