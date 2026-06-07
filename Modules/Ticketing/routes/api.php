<?php

use Illuminate\Support\Facades\Route;
use Modules\Ticketing\Http\Controllers\TicketController;

/*
| TCK-01 Ticketing & Case Management API (DD_API-00).
| Reads: ticket.read; create/comment: ticket.create; assign: ticket.assign;
| resolve/close/raise-WO: ticket.manage.
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('tickets', [TicketController::class, 'index'])->middleware('permission:ticket.read');
    Route::post('tickets', [TicketController::class, 'store'])->middleware(['permission:ticket.create', 'idempotency']);
    Route::get('tickets/{ticket}', [TicketController::class, 'show'])->middleware('permission:ticket.read');
    Route::post('tickets/{ticket}/assign', [TicketController::class, 'assign'])->middleware('permission:ticket.assign');
    Route::post('tickets/{ticket}/comments', [TicketController::class, 'comment'])->middleware('permission:ticket.create');
    Route::post('tickets/{ticket}/work-orders', [TicketController::class, 'createWorkOrder'])->middleware('permission:ticket.manage');
    Route::post('tickets/{ticket}/resolve', [TicketController::class, 'resolve'])->middleware('permission:ticket.manage');
    Route::post('tickets/{ticket}/close', [TicketController::class, 'close'])->middleware('permission:ticket.manage');
});
