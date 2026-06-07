<?php

use App\Foundation\Http\PlatformController;
use App\Http\Controllers\Api\AuthTokenController;
use App\Http\Controllers\SelfCareController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SOPHIX API routes
|--------------------------------------------------------------------------
| Foundation/platform endpoints live here. Each business module registers its
| own routes from Modules/<Module>/routes/api.php (auto-loaded by the module
| service provider), following DD_API-00 ownership and URL conventions.
*/

// --- Platform / foundation ---
Route::get('/health', [PlatformController::class, 'health']);
Route::get('/platform/config', [PlatformController::class, 'runtimeConfig']);

// --- Mobile token auth (FE-APP-02/03 PWAs) ---
Route::post('/auth/token', [AuthTokenController::class, 'token']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthTokenController::class, 'me']);
    Route::post('/auth/logout', [AuthTokenController::class, 'logout']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());
});

// --- FE-APP-04 customer self-care (PWA at /care) ---
Route::middleware(['auth:sanctum', 'permission:selfcare.access'])->prefix('selfcare')->group(function () {
    Route::get('me', [SelfCareController::class, 'me']);
    Route::get('subscriptions', [SelfCareController::class, 'subscriptions']);
    Route::get('subscriptions/{subscription}/restrictions', [SelfCareController::class, 'restrictions']);
    Route::get('invoices', [SelfCareController::class, 'invoices']);
    Route::post('payments', [SelfCareController::class, 'pay'])->middleware('idempotency');
    Route::get('tickets', [SelfCareController::class, 'ticketIndex']);
    Route::post('tickets', [SelfCareController::class, 'raiseTicket'])->middleware('idempotency');
});
