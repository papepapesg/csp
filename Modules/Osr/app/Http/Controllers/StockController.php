<?php

namespace Modules\Osr\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Osr\Models\EquipmentSku;
use Modules\Osr\Models\StockBalance;
use Modules\Osr\Models\StockLocation;
use Modules\Osr\Services\StockService;

/**
 * OSR-01 stock chain + PLM-CFG-06 SKU catalog API.
 */
class StockController extends ApiController
{
    public function __construct(private readonly StockService $stock) {}

    // --- PLM-CFG-06 SKUs ---
    public function skus(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = EquipmentSku::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
            ->orderBy('name')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function storeSku(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sku_id' => ['required', 'string', 'max:128', 'unique:equipment_sku,sku_id'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'in:ROUTER,ONT,STB,SPLITTER,CABLE,WALL_SOCKET,MOUNT_KIT,SMARTCARD'],
            'is_serialized' => ['sometimes', 'boolean'],
            'ownership_semantics' => ['nullable', 'in:RETURNABLE,CONSUMABLE,RENTED'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        return ApiResponse::created(EquipmentSku::query()->create($data));
    }

    // --- OSR-01 locations ---
    public function locations(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = StockLocation::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->boolean('active'), fn ($q) => $q->where('active', true))
            ->orderBy('name')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function storeLocation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'string', 'max:128', 'unique:stock_location,location_id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:WAREHOUSE,CONTRACTOR_VAN'],
            'contractor_id' => ['nullable', 'string'],
        ]);

        return ApiResponse::created(StockLocation::query()->create($data));
    }

    // --- OSR-01 movements + balances ---
    public function move(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sku_id' => ['required', 'string', 'exists:equipment_sku,sku_id'],
            'location_id' => ['required', 'string', 'exists:stock_location,location_id'],
            'quantity' => ['required', 'numeric', 'not_in:0'],
            'reason_code' => ['required', 'in:RECEIPT,ISSUE,TRANSFER_IN,TRANSFER_OUT,INSTALL,RETURN,ADJUST'],
            'reference' => ['nullable', 'string'],
        ]);

        return ApiResponse::created($this->stock->move($data));
    }

    public function balances(Request $request): JsonResponse
    {
        $items = StockBalance::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('location'), fn ($q, $l) => $q->where('location_id', $l))
            ->when($request->query('sku'), fn ($q, $s) => $q->where('sku_id', $s))
            ->get();

        return ApiResponse::item(['items' => $items]);
    }

    // --- OSR-01 §1.5 reservation lifecycle ---
    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sku_id' => ['required', 'string'],
            'location_id' => ['required', 'string'],
        ]);

        return ApiResponse::item($this->stock->availability($data['sku_id'], $data['location_id']));
    }

    public function reserve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sku_id' => ['required', 'string', 'exists:equipment_sku,sku_id'],
            'location_id' => ['required', 'string', 'exists:stock_location,location_id'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'wo_id' => ['nullable', 'string'],
            'reference' => ['nullable', 'string'],
        ]);

        return ApiResponse::created($this->stock->reserve($data['sku_id'], $data['location_id'], (float) $data['qty'], $data['wo_id'] ?? null, $data['reference'] ?? null));
    }

    /** Consume a WO's reservations on install (posts INSTALL movements). */
    public function consumeReservation(string $woId): JsonResponse
    {
        return ApiResponse::item(['woId' => $woId, 'consumed' => $this->stock->consumeReservation($woId)]);
    }

    /** Release a WO's reservations (e.g. cancellation). */
    public function releaseReservation(string $woId): JsonResponse
    {
        return ApiResponse::item(['woId' => $woId, 'released' => $this->stock->releaseReservation($woId)]);
    }
}
