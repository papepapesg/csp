<?php

use Illuminate\Support\Facades\Route;
use Modules\Catalog\Http\Controllers\ConfigCatalogController;
use Modules\Catalog\Http\Controllers\BundleController;
use Modules\Catalog\Http\Controllers\CampaignController;
use Modules\Catalog\Http\Controllers\DiscountController;
use Modules\Catalog\Http\Controllers\HomePassController;
use Modules\Catalog\Http\Controllers\PackageController;
use Modules\Catalog\Http\Controllers\ServiceClassController;
use Modules\Catalog\Http\Controllers\ServiceController;
use Modules\Catalog\Http\Controllers\TaxController;
use Modules\Catalog\Http\Controllers\TechRegionController;
use Modules\Catalog\Http\Controllers\WalletCatalogController;

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
    Route::get('homepass/eligible', [HomePassController::class, 'eligible'])->middleware('permission:catalog.read');
    Route::post('homepass', [HomePassController::class, 'store'])->middleware(['permission:catalog.manage', 'idempotency']);
    Route::get('homepass/{homepass}', [HomePassController::class, 'show'])->middleware('permission:catalog.read');
    Route::patch('homepass/{homepass}/status', [HomePassController::class, 'changeStatus'])->middleware('permission:catalog.manage');

    // PLM-CFG-02 tax compute
    Route::post('tax/compute', [TaxController::class, 'compute'])->middleware('permission:catalog.read');

    // PLM-CFG-04 / SIP-03 / DIS-OP-01 discounts
    Route::get('discounts', [DiscountController::class, 'index'])->middleware('permission:catalog.read');
    Route::post('discounts', [DiscountController::class, 'store'])->middleware('permission:catalog.manage');
    Route::post('discounts/assign', [DiscountController::class, 'assign'])->middleware('permission:catalog.manage');
    Route::post('discounts/compute', [DiscountController::class, 'compute'])->middleware('permission:catalog.read');

    // PLM-CFG-03 wallet catalog (the rich `wallet` entity: applicability, precedence, lifecycle)
    Route::get('wallet-catalog', [WalletCatalogController::class, 'index'])->middleware('permission:catalog.read');
    Route::post('wallet-catalog', [WalletCatalogController::class, 'store'])->middleware(['permission:catalog.manage', 'idempotency']);
    Route::get('wallet-catalog/{wallet}', [WalletCatalogController::class, 'show'])->middleware('permission:catalog.read');
    Route::patch('wallet-catalog/{wallet}', [WalletCatalogController::class, 'update'])->middleware('permission:catalog.manage');
    Route::post('wallet-catalog/{wallet}/activate', [WalletCatalogController::class, 'activate'])->middleware('permission:catalog.manage');
    Route::post('wallet-catalog/{wallet}/retire', [WalletCatalogController::class, 'retire'])->middleware('permission:catalog.manage');

    // SIP-04 commercial bundles (launch lifecycle + availability + migration paths)
    Route::get('commercial-bundles', [BundleController::class, 'index'])->middleware('permission:catalog.read');
    Route::get('commercial-bundles/available', [BundleController::class, 'available'])->middleware('permission:catalog.read');
    Route::post('commercial-bundles', [BundleController::class, 'store'])->middleware(['permission:catalog.manage', 'idempotency']);
    Route::post('commercial-bundles/migration-preview', [BundleController::class, 'migrationPreview'])->middleware('permission:catalog.read');
    Route::get('commercial-bundles/{bundle}', [BundleController::class, 'show'])->middleware('permission:catalog.read');
    Route::post('commercial-bundles/{bundle}/validate', [BundleController::class, 'validateBundle'])->middleware('permission:catalog.manage');
    Route::post('commercial-bundles/{bundle}/submit-review', [BundleController::class, 'submitReview'])->middleware('permission:catalog.manage');
    Route::post('commercial-bundles/{bundle}/approve', [BundleController::class, 'approve'])->middleware('permission:catalog.manage');
    Route::post('commercial-bundles/{bundle}/activate', [BundleController::class, 'activate'])->middleware('permission:catalog.manage');
    Route::post('commercial-bundles/{bundle}/retire', [BundleController::class, 'retire'])->middleware('permission:catalog.manage');
    Route::post('commercial-bundles/{bundle}/migration-rules', [BundleController::class, 'storeMigrationRule'])->middleware('permission:catalog.manage');

    // SIP-05 promotional campaigns (MVP: lifecycle + eligibility + redemption)
    Route::get('campaigns', [CampaignController::class, 'index'])->middleware('permission:catalog.read');
    Route::post('campaigns', [CampaignController::class, 'store'])->middleware(['permission:catalog.manage', 'idempotency']);
    Route::post('campaigns/{campaign}/validate', [CampaignController::class, 'validate'])->middleware('permission:catalog.manage');
    Route::post('campaigns/{campaign}/activate', [CampaignController::class, 'activate'])->middleware('permission:catalog.manage');
    Route::post('campaigns/{campaign}/pause', [CampaignController::class, 'pause'])->middleware('permission:catalog.manage');
    Route::post('campaigns/{campaign}/end', [CampaignController::class, 'end'])->middleware('permission:catalog.manage');
    Route::post('campaigns/{campaign}/check-eligibility', [CampaignController::class, 'checkEligibility'])->middleware('permission:catalog.read');
    Route::post('campaigns/{campaign}/participate', [CampaignController::class, 'participate'])->middleware(['permission:catalog.manage', 'idempotency']);

    // PLM config catalogs (wallet / adjustment-type / voice-tariff / equipment-type)
    Route::get('config/{catalog}', [ConfigCatalogController::class, 'index'])->middleware('permission:catalog.read');
    Route::post('config/{catalog}', [ConfigCatalogController::class, 'store'])->middleware('permission:catalog.manage');
});
