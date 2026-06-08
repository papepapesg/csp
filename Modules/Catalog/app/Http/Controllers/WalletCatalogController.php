<?php

namespace Modules\Catalog\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Models\WalletCatalog;
use Modules\Catalog\Services\WalletCatalogService;

/**
 * PLM-CFG-03 wallet catalog API — CRUD + DRAFT→ACTIVE→RETIRED lifecycle. Reads
 * require catalog.read; writes require catalog.manage (the WALLET_ADMIN role maps
 * onto catalog.manage in the local RBAC).
 */
class WalletCatalogController extends ApiController
{
    public function __construct(private readonly WalletCatalogService $wallets) {}

    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = WalletCatalog::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('walletTypeCode'), fn ($q, $t) => $q->where('wallet_type_code', $t))
            ->orderBy('charging_precedence')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function show(WalletCatalog $wallet): JsonResponse
    {
        return ApiResponse::item($wallet);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'description' => ['required', 'string', 'max:255'],
            'wallet_type_code' => ['required', 'string', 'max:64'],
            'currency' => ['required', 'string', 'size:3'],
            'decimal_precision' => ['nullable', 'integer', 'min:0', 'max:4'],
            'applicability' => ['nullable', 'in:PREPAID_ONLY,POSTPAID_ONLY,ANY'],
            'expires' => ['sometimes', 'boolean'],
            'expiry_period_days' => ['nullable', 'integer', 'min:1'],
            'charging_precedence' => ['nullable', 'integer', 'min:0'],
            'refillable' => ['sometimes', 'boolean'],
            'points_to_currency_rate' => ['nullable', 'numeric', 'min:0'],
        ]);

        return ApiResponse::created($this->wallets->create($data));
    }

    public function update(Request $request, WalletCatalog $wallet): JsonResponse
    {
        $data = $request->validate([
            'description' => ['sometimes', 'string', 'max:255'],
            'applicability' => ['sometimes', 'in:PREPAID_ONLY,POSTPAID_ONLY,ANY'],
            'expires' => ['sometimes', 'boolean'],
            'expiry_period_days' => ['nullable', 'integer', 'min:1'],
            'charging_precedence' => ['sometimes', 'integer', 'min:0'],
            'refillable' => ['sometimes', 'boolean'],
            'points_to_currency_rate' => ['nullable', 'numeric', 'min:0'],
        ]);

        return ApiResponse::item($this->wallets->update($wallet, $data));
    }

    public function activate(WalletCatalog $wallet): JsonResponse
    {
        return ApiResponse::item($this->wallets->activate($wallet));
    }

    public function retire(WalletCatalog $wallet): JsonResponse
    {
        return ApiResponse::item($this->wallets->retire($wallet));
    }
}
