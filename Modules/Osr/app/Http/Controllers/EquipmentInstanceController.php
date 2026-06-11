<?php

namespace Modules\Osr\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Osr\Models\EquipmentInstance;
use Modules\Osr\Services\EquipmentInstanceService;

/**
 * OSR-INSTANCE-01 serialized equipment registry API.
 */
class EquipmentInstanceController extends ApiController
{
    public function __construct(private readonly EquipmentInstanceService $instances) {}

    /** GET /api/equipment-instances?serial=&state=&locationId=&skuId= */
    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = EquipmentInstance::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('serial'), fn ($q, $s) => $q->where('serial', $s))
            ->when($request->query('state'), fn ($q, $s) => $q->whereIn('state', explode(',', $s)))
            ->when($request->query('locationId'), fn ($q, $l) => $q->where('location_id', $l))
            ->when($request->query('skuId'), fn ($q, $s) => $q->where('sku_id', $s))
            ->when($request->query('subscriptionId'), fn ($q, $s) => $q->where('subscription_id', $s))
            ->orderByDesc('updated_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sku_id' => ['required', 'string', 'exists:equipment_sku,sku_id'],
            'serial' => ['required', 'string', 'max:128'],
            'mac_address' => ['nullable', 'string', 'max:64'],
            'location_id' => ['nullable', 'string'],
        ]);

        return ApiResponse::created($this->instances->register($data));
    }

    public function show(EquipmentInstance $equipmentInstance): JsonResponse
    {
        return ApiResponse::item($equipmentInstance->load('lifecycleEvents'));
    }

    /** POST /api/equipment-instances/{equipmentInstance}/transition */
    public function transition(Request $request, EquipmentInstance $equipmentInstance): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', 'in:IN_MAIN_WAREHOUSE,IN_CONTRACTOR_STOCK,IN_FIELD_ACTIVE,IN_FIELD_DEFECTIVE,RECOVERED_BY_CONTRACTOR,RESERVED_FOR_WO,RETURNED,FAULTY,RETIRED'],
            'location_id' => ['nullable', 'string'],
            'customer_id' => ['nullable', 'string'],
            'subscription_id' => ['nullable', 'string'],
            'reference' => ['nullable', 'string'],
            'contractor_id' => ['nullable', 'string'], // INST-7: required for RECOVERED_BY_CONTRACTOR (enforced in service)
            'reason_code' => ['nullable', 'string'],
        ]);

        return ApiResponse::item($this->instances->transition($equipmentInstance, $data['state'], $data));
    }
}
