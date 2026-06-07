<?php

namespace Modules\Osr\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Osr\Models\PurchaseOrder;
use Modules\Osr\Models\StockCountSession;
use Modules\Osr\Services\InventoryAuditService;
use Modules\Osr\Services\ProcurementService;

/** OSR-02 procurement + OSR-05 inventory audit API. */
class ProcurementController extends ApiController
{
    public function __construct(
        private readonly ProcurementService $procurement,
        private readonly InventoryAuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::item(['items' => PurchaseOrder::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))->orderByDesc('created_at')->limit(100)->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier' => ['required', 'string'],
            'location_id' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku_id' => ['required', 'string'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_cost' => ['nullable', 'numeric'],
        ]);

        return ApiResponse::created($this->procurement->create($data + ['created_by' => $request->user()?->uid]));
    }

    public function approve(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return ApiResponse::item($this->procurement->approve($purchaseOrder));
    }

    public function receive(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return ApiResponse::item($this->procurement->receive($purchaseOrder));
    }

    // ---- OSR-05 inventory audit ----

    public function openCount(Request $request): JsonResponse
    {
        $data = $request->validate(['location_id' => ['required', 'string']]);

        return ApiResponse::created($this->audit->open($data['location_id'], $request->user()?->uid));
    }

    public function count(Request $request, StockCountSession $stockCountSession): JsonResponse
    {
        $data = $request->validate([
            'counts' => ['required', 'array', 'min:1'],
            'counts.*.sku_id' => ['required', 'string'],
            'counts.*.counted_qty' => ['required', 'integer', 'min:0'],
        ]);

        return ApiResponse::item($this->audit->count($stockCountSession, $data['counts']));
    }

    public function reconcile(StockCountSession $stockCountSession): JsonResponse
    {
        return ApiResponse::item($this->audit->reconcile($stockCountSession));
    }
}
