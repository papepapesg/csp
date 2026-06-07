<?php

use Illuminate\Support\Facades\Route;
use Modules\Catalog\Http\Controllers\HomePassController;
use Modules\Catalog\Http\Controllers\PackageController;
use Modules\Catalog\Http\Controllers\ServiceClassController;
use Modules\Catalog\Http\Controllers\ServiceController;
use Modules\Catalog\Http\Controllers\TechRegionController;

/*
| Catalog & reference-data API (PLM-CFG-01, SIP-01, RLM-CFG-01, ILM-CFG-02).
| Reads require catalog.read; writes require catalog.manage (DD_EM-CFG-03).
*/

Route::middleware('auth:sanctum')->group(function () {
    // PLM — Service classes & services
    Route::get('service-classes', [ServiceClassController::class, 'index'])->middleware('permission:catalog.read');
    Route::post('service-classes', [ServiceClassController::class, 'store'])->middleware('permission:catalog.manage');
    Route::get('service-classes/{serviceClass}', [ServiceClassController::class, 'show'])->middleware('permission:catalog.read');

    Route::get('services', [ServiceController::class, 'index'])->middleware('permission:catalog.read');
    Route::post('services', [ServiceController::class, 'store'])->middleware(['permission:catalog.manage', 'idempotency']);
    Route::get('services/{service}', [ServiceController::class, 'show'])->middleware('permission:catalog.read');
    Route::patch('services/{service}', [ServiceController::class, 'update'])->middleware('permission:catalog.manage');

    // SIP — Packages
    Route::get('packages', [PackageController::class, 'index'])->middleware('permission:catalog.read');
    Route::post('packages', [PackageController::class, 'store'])->middleware(['permission:catalog.manage', 'idempotency']);
    Route::get('packages/{package}', [PackageController::class, 'show'])->middleware('permission:catalog.read');
    Route::patch('packages/{package}', [PackageController::class, 'update'])->middleware('permission:catalog.manage');
    Route::post('packages/{package}/versions', [PackageController::class, 'addVersion'])->middleware('permission:catalog.manage');
    Route::post('packages/{package}/activate', [PackageController::class, 'activate'])->middleware(['permission:catalog.manage', 'idempotency']);

    // ILM-CFG-02 — Tech regions
    Route::get('tech-regions', [TechRegionController::class, 'index'])->middleware('permission:catalog.read');
    Route::post('tech-regions', [TechRegionController::class, 'store'])->middleware('permission:catalog.manage');
    Route::get('tech-regions/{techRegion}', [TechRegionController::class, 'show'])->middleware('permission:catalog.read');
    Route::patch('tech-regions/{techRegion}', [TechRegionController::class, 'update'])->middleware('permission:catalog.manage');

    // RLM-CFG-01 — HomePass serviceability
    Route::get('homepass', [HomePassController::class, 'index'])->middleware('permission:catalog.read');
    Route::post('homepass', [HomePassController::class, 'store'])->middleware(['permission:catalog.manage', 'idempotency']);
    Route::get('homepass/{homepass}', [HomePassController::class, 'show'])->middleware('permission:catalog.read');
    Route::patch('homepass/{homepass}/status', [HomePassController::class, 'changeStatus'])->middleware('permission:catalog.manage');
});
