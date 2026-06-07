<?php

namespace Modules\Osr\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Osr\Models\EquipmentSwapRequest;
use Modules\Osr\Services\SwapRequestService;

/**
 * OSR-RMA-01 swap-request API. Kind-specific create endpoint per the satellite
 * convention (POST /swap-requests/{kind}); kind-agnostic read + field-visit.
 */
class SwapRequestController extends ApiController
{
    public function __construct(private readonly SwapRequestService $swaps) {}

    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = EquipmentSwapRequest::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->orderByDesc('created_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function show(EquipmentSwapRequest $swapRequest): JsonResponse
    {
        return ApiResponse::item($swapRequest);
    }

    /** POST /api/swap-requests/{kind} */
    public function store(Request $request, string $kind): JsonResponse
    {
        $kind = strtoupper($kind) === 'HFC' ? 'SWAP_HFC' : (strtoupper($kind) === 'GPON' ? 'SWAP_GPON' : strtoupper($kind));
        $data = $request->validate([
            'source_instance_id' => ['required', 'string'],
            'target_instance_id' => ['nullable', 'string'],
            'subscription_id' => ['nullable', 'string'],
            'customer_id' => ['nullable', 'string'],
            'homepass_id' => ['nullable', 'string'],
            'recovery_contractor_id' => ['required', 'string'],
            'flow_payload' => ['nullable', 'array'],
        ]);

        $swap = $this->swaps->create($kind, $data);

        return ApiResponse::accepted(
            entityId: $swap->swap_id,
            extra: ['kind' => $kind, 'status' => $swap->status, 'processInstanceId' => $swap->process_instance_id],
        );
    }

    /** POST /api/swap-requests/{swapRequest}/field-visit */
    public function fieldVisit(Request $request, EquipmentSwapRequest $swapRequest): JsonResponse
    {
        $data = $request->validate(['defect_confirmed' => ['nullable', 'boolean']]);
        $this->swaps->confirmFieldVisit($swapRequest, (bool) ($data['defect_confirmed'] ?? true));

        return ApiResponse::accepted(entityId: $swapRequest->swap_id, nextAction: 'TRACK_OPERATION');
    }
}
