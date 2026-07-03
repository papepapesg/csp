<?php

use Illuminate\Support\Facades\Route;
use Modules\Catalog\Plm\Http\Controllers\ConfigCatalogController;
use Modules\Catalog\Plm\Http\Controllers\BundleController;
use Modules\Catalog\Discount\Http\Controllers\CampaignController;
use Modules\Catalog\Discount\Http\Controllers\DiscountController;
use Modules\Catalog\Network\Http\Controllers\HomePassController;
use Modules\Catalog\Plm\Http\Controllers\PackageController;
use Modules\Catalog\Plm\Http\Controllers\PackageLaunchController;
use Modules\Catalog\Plm\Http\Controllers\ServiceClassController;
use Modules\Catalog\Plm\Http\Controllers\ServiceController;
use Modules\Catalog\Tax\Http\Controllers\TaxController;
use Modules\Catalog\Network\Http\Controllers\TechRegionController;
use Modules\Catalog\Rating\Http\Controllers\VoiceTariffController;

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
    // SIP-02 sellable-package read model (Backoffice/sales/order-capture/self-care). Declared
    // before packages/{package} so the literal segment is not bound as a package id.
    Route::get('packages/available', [PackageLaunchController::class, 'available'])->middleware('permission:catalog.read');
    Route::get('packages/{package}', [PackageController::class, 'show'])->middleware('permission:catalog.read');
    Route::patch('packages/{package}', [PackageController::class, 'update'])->middleware('permission:catalog.manage');
    Route::post('packages/{package}/versions', [PackageController::class, 'addVersion'])->middleware('permission:catalog.manage');
    Route::post('packages/{package}/activate', [PackageController::class, 'activate'])->middleware(['permission:catalog.manage', 'idempotency']);
    Route::post('packages/{package}/availability/suspend', [PackageLaunchController::class, 'suspendAvailability'])->middleware('permission:catalog.manage');
    Route::post('packages/{package}/availability/resume', [PackageLaunchController::class, 'resumeAvailability'])->middleware('permission:catalog.manage');

    // SIP-02 package launch lifecycle (launch plan → validate → review/approve → activate → retire).
    Route::get('package-launch-plans', [PackageLaunchController::class, 'index'])->middleware('permission:catalog.read');
    Route::post('package-launch-plans', [PackageLaunchController::class, 'store'])->middleware(['permission:catalog.manage', 'idempotency']);
    Route::get('package-launch-plans/{launchPlan}', [PackageLaunchController::class, 'show'])->middleware('permission:catalog.read');
    Route::post('package-launch-plans/{launchPlan}/validate', [PackageLaunchController::class, 'validatePlan'])->middleware('permission:catalog.manage');
    Route::post('package-launch-plans/{launchPlan}/submit-review', [PackageLaunchController::class, 'submitReview'])->middleware('permission:catalog.manage');
    Route::post('package-launch-plans/{launchPlan}/approval-outcome', [PackageLaunchController::class, 'approvalOutcome'])->middleware('permission:catalog.manage');
    Route::post('package-launch-plans/{launchPlan}/activate', [PackageLaunchController::class, 'activate'])->middleware(['permission:catalog.manage', 'idempotency']);
    Route::post('package-launch-plans/run-due', [PackageLaunchController::class, 'runDue'])->middleware('permission:catalog.manage');
    Route::post('package-retirement-plans', [PackageLaunchController::class, 'storeRetirement'])->middleware(['permission:catalog.manage', 'idempotency']);

    // ILM-CFG-02 — Tech regions
    Route::get('tech-regions', [TechRegionController::class, 'index'])->middleware('permission:catalog.read');
    Route::post('tech-regions', [TechRegionController::class, 'store'])->middleware('permission:catalog.manage');
    Route::get('tech-regions/{techRegion}', [TechRegionController::class, 'show'])->middleware('permission:catalog.read');
    Route::patch('tech-regions/{techRegion}', [TechRegionController::class, 'update'])->middleware('permission:catalog.manage');

    // RLM-CFG-01 TechContractor / skill / coverage.
    $tc = \Modules\Catalog\Network\Http\Controllers\TechCoverageController::class;
    Route::get('tech-contractor-skills', [$tc, 'skills'])->middleware('permission:catalog.read');
    Route::post('tech-contractor-skills', [$tc, 'storeSkill'])->middleware('permission:catalog.manage');
    Route::get('tech-contractors', [$tc, 'contractors'])->middleware('permission:catalog.read');
    Route::post('tech-contractors', [$tc, 'storeContractor'])->middleware('permission:catalog.manage');
    Route::post('tech-contractors/{techContractor}/retire', [$tc, 'retireContractor'])->middleware('permission:catalog.manage');
    Route::post('tech-regions/{techRegion}/contractors', [$tc, 'assignContractor'])->middleware('permission:catalog.manage');
    Route::post('tech-regions/{techRegion}/activate', [$tc, 'activateRegion'])->middleware('permission:catalog.manage');
    Route::post('tech-regions/{techRegion}/retire', [$tc, 'retireRegion'])->middleware('permission:catalog.manage');

    // RLM-CFG-01 — HomePass serviceability
    Route::get('homepass', [HomePassController::class, 'index'])->middleware('permission:catalog.read');
    Route::get('homepass/eligible', [HomePassController::class, 'eligible'])->middleware('permission:catalog.read');
    Route::post('homepass/bulk-import', [HomePassController::class, 'bulkImport'])->middleware('permission:catalog.manage');
    Route::post('homepass/{homepass}/enrich-from-geo', [HomePassController::class, 'enrichFromGeo'])->middleware('permission:catalog.manage');
    Route::post('homepass', [HomePassController::class, 'store'])->middleware(['permission:catalog.manage', 'idempotency']);
    Route::get('homepass/{homepass}', [HomePassController::class, 'show'])->middleware('permission:catalog.read');
    Route::patch('homepass/{homepass}/status', [HomePassController::class, 'changeStatus'])->middleware('permission:catalog.manage');
    Route::patch('homepass/{homepass}/network-path', [HomePassController::class, 'setNetworkPath'])->middleware('permission:catalog.manage');
    Route::patch('homepass/{homepass}/address', [HomePassController::class, 'correctAddress'])->middleware('permission:catalog.manage');
    Route::get('homepass/{homepass}/eligible-contractors', [HomePassController::class, 'eligibleContractors'])->middleware('permission:catalog.read');

    // RLM-CFG-01 network_node + house_type reference catalogs.
    Route::get('network-nodes', [\Modules\Catalog\Network\Http\Controllers\NetworkCatalogController::class, 'nodes'])->middleware('permission:catalog.read');
    Route::post('network-nodes', [\Modules\Catalog\Network\Http\Controllers\NetworkCatalogController::class, 'storeNode'])->middleware('permission:catalog.manage');
    Route::post('network-nodes/{networkNode}/retire', [\Modules\Catalog\Network\Http\Controllers\NetworkCatalogController::class, 'retireNode'])->middleware('permission:catalog.manage');
    Route::get('house-types', [\Modules\Catalog\Network\Http\Controllers\NetworkCatalogController::class, 'houseTypes'])->middleware('permission:catalog.read');
    Route::post('house-types', [\Modules\Catalog\Network\Http\Controllers\NetworkCatalogController::class, 'storeHouseType'])->middleware('permission:catalog.manage');

    // PLM-CFG-02 tax compute + admin config (rules/groups, effective-dated versioning)
    Route::post('tax/compute', [TaxController::class, 'compute'])->middleware('permission:catalog.read');
    Route::get('tax/rules', [TaxController::class, 'rules'])->middleware('permission:catalog.read');
    Route::post('tax/rules', [TaxController::class, 'storeRule'])->middleware('permission:catalog.manage');
    Route::patch('tax/rules/{taxRule}', [TaxController::class, 'updateRule'])->middleware('permission:catalog.manage');
    Route::get('tax/groups', [TaxController::class, 'groups'])->middleware('permission:catalog.read');
    Route::post('tax/groups', [TaxController::class, 'storeGroup'])->middleware('permission:catalog.manage');
    Route::patch('tax/groups/{taxGroup}', [TaxController::class, 'updateGroup'])->middleware('permission:catalog.manage');

    // PLM-CFG-07 voice tariff catalog (plans / zones / prefixes / time bands / rates / allowances / bindings)
    Route::get('plm/voice-tariff-plans', [VoiceTariffController::class, 'plans'])->middleware('permission:catalog.read');
    Route::post('plm/voice-tariff-plans', [VoiceTariffController::class, 'storePlan'])->middleware(['permission:catalog.manage', 'idempotency']);
    Route::get('plm/voice-tariff-plans/{tariffPlan}', [VoiceTariffController::class, 'showPlan'])->middleware('permission:catalog.read');
    Route::patch('plm/voice-tariff-plans/{tariffPlan}', [VoiceTariffController::class, 'updatePlan'])->middleware('permission:catalog.manage');
    Route::post('plm/voice-tariff-plans/{tariffPlan}/activate', [VoiceTariffController::class, 'activatePlan'])->middleware('permission:catalog.manage');
    Route::post('plm/voice-tariff-plans/{tariffPlan}/retire', [VoiceTariffController::class, 'retirePlan'])->middleware('permission:catalog.manage');

    Route::get('plm/voice-destination-zones', [VoiceTariffController::class, 'zones'])->middleware('permission:catalog.read');
    Route::post('plm/voice-destination-zones', [VoiceTariffController::class, 'storeZone'])->middleware('permission:catalog.manage');

    Route::get('plm/voice-time-bands', [VoiceTariffController::class, 'timeBands'])->middleware('permission:catalog.read');
    Route::post('plm/voice-time-bands', [VoiceTariffController::class, 'storeTimeBand'])->middleware('permission:catalog.manage');

    Route::get('plm/voice-destination-prefixes', [VoiceTariffController::class, 'prefixes'])->middleware('permission:catalog.read');
    Route::post('plm/voice-destination-prefixes', [VoiceTariffController::class, 'storePrefix'])->middleware('permission:catalog.manage');
    Route::post('plm/voice-destination-prefixes/bulk-import', [VoiceTariffController::class, 'bulkImportPrefixes'])->middleware('permission:catalog.manage');

    Route::get('plm/voice-tariff-rates', [VoiceTariffController::class, 'rates'])->middleware('permission:catalog.read');
    Route::post('plm/voice-tariff-rates', [VoiceTariffController::class, 'storeRate'])->middleware('permission:catalog.manage');
    Route::post('plm/voice-tariff-rates/bulk-import', [VoiceTariffController::class, 'bulkImportRates'])->middleware('permission:catalog.manage');
    Route::post('plm/voice-tariff-rates/validate-overlap', [VoiceTariffController::class, 'validateOverlap'])->middleware('permission:catalog.read');

    Route::get('plm/voice-tariff-allowances', [VoiceTariffController::class, 'allowances'])->middleware('permission:catalog.read');
    Route::post('plm/voice-tariff-allowances', [VoiceTariffController::class, 'storeAllowance'])->middleware('permission:catalog.manage');

    Route::get('plm/voice-tariff-bindings', [VoiceTariffController::class, 'bindings'])->middleware('permission:catalog.read');
    Route::post('plm/voice-tariff-bindings', [VoiceTariffController::class, 'storeBinding'])->middleware('permission:catalog.manage');

    Route::post('plm/voice-rating/lookup', [VoiceTariffController::class, 'ratingLookup'])->middleware('permission:catalog.read');
    Route::post('plm/voice-rating/rate', [VoiceTariffController::class, 'rateCall'])->middleware('permission:catalog.read');

    // PLM-CFG-04 / SIP-03 / DIS-OP-01 discounts
    Route::get('discounts', [DiscountController::class, 'index'])->middleware('permission:catalog.read');
    Route::post('discounts', [DiscountController::class, 'store'])->middleware('permission:catalog.manage');
    Route::post('discounts/assign', [DiscountController::class, 'assign'])->middleware('permission:catalog.manage'); // legacy thin assign
    Route::post('discounts/compute', [DiscountController::class, 'compute'])->middleware('permission:catalog.read');

    // SIP-03 discount assignment lifecycle + DIS-OP-01 runtime query
    Route::post('discount-assignments', [DiscountController::class, 'createAssignment'])->middleware(['permission:catalog.manage', 'idempotency']);
    Route::post('discount-assignments/preview', [DiscountController::class, 'previewAssignment'])->middleware('permission:catalog.read');
    Route::get('discount-assignments/effective', [DiscountController::class, 'effective'])->middleware('permission:catalog.read');
    Route::post('discount-assignments/{discountAssignment}/cancel', [DiscountController::class, 'cancelAssignment'])->middleware('permission:catalog.manage');
    Route::post('discount-assignments/{discountAssignment}/approval-outcome', [DiscountController::class, 'approvalOutcome'])->middleware('permission:catalog.manage');

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
