<?php

namespace Modules\WorkOrder\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\WorkOrder\Models\WorkOrder;
use Modules\WorkOrder\Services\WorkOrderService;

/**
 * WO-01 Work Order API. Create/assign by dispatchers (workorder.assign);
 * start/finalize by field technicians (workorder.execute).
 */
class WorkOrderController extends ApiController
{
    public function __construct(private readonly WorkOrderService $service) {}

    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = WorkOrder::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('accountId'), fn ($q, $a) => $q->where('account_id', $a))
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->query('techRegionId'), fn ($q, $r) => $q->where('tech_region_id', $r))
            ->orderByDesc('created_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:INSTALLATION,SUPPORT,SHIFTING,RELOCATION,EQUIPMENT,NOC'],
            'priority' => ['nullable', 'in:LOW,NORMAL,HIGH,URGENT'],
            'account_id' => ['nullable', 'string'],
            'subscription_id' => ['nullable', 'string'],
            'customer_id' => ['nullable', 'string'],
            'homepass_id' => ['nullable', 'string'],
            'tech_region_id' => ['nullable', 'string'],
            'source_type' => ['nullable', 'in:TICKET,SUBSCRIPTION_OP,FULFILLMENT,MANUAL'],
            'source_ref' => ['nullable', 'string'],
            'scheduled_at' => ['nullable', 'date'],
        ]);
        $data['created_by'] = $request->user()?->uid;

        return ApiResponse::created($this->service->create($data));
    }

    public function show(WorkOrder $workOrder): JsonResponse
    {
        return ApiResponse::item($workOrder->load('statusHistory'));
    }

    public function assign(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $data = $request->validate([
            'contractor_id' => ['nullable', 'string'],
            'team_id' => ['nullable', 'string'],
            'assigned_technician_id' => ['nullable', 'string'],
        ]);

        return ApiResponse::item($this->service->assign($workOrder, $data, $request->user()?->uid));
    }

    public function start(Request $request, WorkOrder $workOrder): JsonResponse
    {
        return ApiResponse::item($this->service->start($workOrder, $request->user()?->uid));
    }

    public function finalize(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $data = $request->validate([
            'resolution_code' => ['nullable', 'string', 'max:64'],
            'findings' => ['nullable', 'array'],
        ]);

        return ApiResponse::item($this->service->finalize($workOrder, $data, $request->user()?->uid));
    }

    public function cancel(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return ApiResponse::item($this->service->cancel($workOrder, $data['reason'] ?? null, $request->user()?->uid));
    }
}
