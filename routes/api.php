<?php

use App\Foundation\Http\PlatformController;
use App\Http\Controllers\Api\AuthTokenController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\CustomerTimelineController;
use App\Http\Controllers\FranchiseController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\UssdController;
use App\Http\Controllers\FileController;
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
Route::middleware('auth:sanctum')->get('/dashboard/summary', [\App\Http\Controllers\DashboardController::class, 'summary']);
Route::middleware('auth:sanctum')->get('/search', [\App\Http\Controllers\SearchController::class, 'index']);

// --- Mobile token auth (FE-APP-02/03 PWAs) ---
Route::post('/auth/token', [AuthTokenController::class, 'token']);
Route::post('/ussd', [UssdController::class, 'handle']); // USSD gateway webhook (Africa's Talking style)
Route::post('/channels/ussd/sessions', [UssdController::class, 'session']); // FE-CH-USSD-01 normalized endpoint
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthTokenController::class, 'me']);
    Route::post('/auth/logout', [AuthTokenController::class, 'logout']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());

    // FOUNDATION_FILE_STORAGE
    Route::post('/files', [FileController::class, 'store']);
    Route::get('/files/{file}', [FileController::class, 'show']);
    Route::get('/files/{file}/download', [FileController::class, 'download']);
});

// --- EM-CFG-04 approval workflow catalog ---
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/approval-definitions', [ApprovalController::class, 'definitions']);
    Route::post('/approval-definitions', [ApprovalController::class, 'storeDefinition'])->middleware('permission:rbac.manage');
    Route::get('/approvals', [ApprovalController::class, 'index']);
    Route::post('/approvals', [ApprovalController::class, 'store'])->middleware('idempotency');
    Route::post('/approvals/{approvalRequest}/decide', [ApprovalController::class, 'decide']);
});

// --- Operator deployment configuration (identity/locale/currency/theme/logs) ---
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/operator-config', [\App\Foundation\Http\Controllers\OperatorConfigController::class, 'show']);
    Route::patch('/operator-config', [\App\Foundation\Http\Controllers\OperatorConfigController::class, 'update'])->middleware('permission:itops.manage');
});

// --- i18n resource catalog (Localization Studio) ---
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/i18n/resources', [\App\Foundation\Http\Controllers\I18nController::class, 'resources']);
    Route::get('/i18n/meta', [\App\Foundation\Http\Controllers\I18nController::class, 'meta']);
    Route::get('/i18n/translations', [\App\Foundation\Http\Controllers\I18nController::class, 'index']);
    Route::post('/i18n/translations', [\App\Foundation\Http\Controllers\I18nController::class, 'upsert'])->middleware('permission:itops.manage');
    Route::delete('/i18n/translations/{translation}', [\App\Foundation\Http\Controllers\I18nController::class, 'destroy'])->middleware('permission:itops.manage');
});

// --- FOUNDATION_CACHE §11 admin operations ---
Route::middleware(['auth:sanctum', 'permission:itops.manage'])->group(function () {
    Route::post('/admin/cache/invalidate', [\App\Foundation\Http\Controllers\CacheAdminController::class, 'invalidate']);
    Route::get('/admin/cache/stats', [\App\Foundation\Http\Controllers\CacheAdminController::class, 'stats']);
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


// --- EM-01 franchise + SALES-01 leads + CUST-INT-01 timeline ---
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/franchises', [FranchiseController::class, 'index'])->middleware('permission:franchise.manage');
    Route::post('/franchises', [FranchiseController::class, 'store'])->middleware('permission:franchise.manage');

    Route::get('/leads', [LeadController::class, 'index'])->middleware('permission:customer.read');
    Route::post('/leads', [LeadController::class, 'store'])->middleware(['permission:customer.create', 'idempotency']);
    Route::post('/leads/{lead}/qualify', [LeadController::class, 'qualify'])->middleware('permission:customer.create');
    Route::post('/leads/{lead}/convert', [LeadController::class, 'convert'])->middleware('permission:customer.create');
    Route::post('/leads/{lead}/lose', [LeadController::class, 'lose'])->middleware('permission:customer.create');

    // SALES-01 full pipeline (FE-APP-02 + Backoffice)
    $sales = \App\Http\Controllers\SalesController::class;
    Route::get('/sales/leads', [$sales, 'leads'])->middleware('permission:customer.read');
    Route::post('/sales/leads', [$sales, 'createLead'])->middleware(['permission:customer.create', 'idempotency']);
    Route::get('/sales/leads/{lead}', [$sales, 'showLead'])->middleware('permission:customer.read');
    Route::post('/sales/leads/{lead}/assign', [$sales, 'assign'])->middleware('permission:customer.create');
    Route::post('/sales/leads/{lead}/activities', [$sales, 'addActivity'])->middleware('permission:customer.create');
    Route::post('/sales/leads/{lead}/qualify', [$sales, 'qualify'])->middleware('permission:customer.create');
    Route::post('/sales/leads/{lead}/convert-to-order', [$sales, 'convertToOrder'])->middleware(['permission:customer.create', 'idempotency']);
    Route::post('/sales/leads/{lead}/lose', [$sales, 'lose'])->middleware('permission:customer.create');
    Route::get('/sales/territories', [$sales, 'territories'])->middleware('permission:customer.read');
    Route::post('/sales/territories', [$sales, 'createTerritory'])->middleware('permission:franchise.manage');
    Route::get('/sales/agents/{agentId}/daily-work', [$sales, 'dailyWork'])->middleware('permission:customer.read');

    Route::get('/customers/{customerId}/timeline', [CustomerTimelineController::class, 'show'])->middleware('permission:customer.read');
});
