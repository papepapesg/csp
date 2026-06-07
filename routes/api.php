<?php

use App\Foundation\Http\PlatformController;
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

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());
});
