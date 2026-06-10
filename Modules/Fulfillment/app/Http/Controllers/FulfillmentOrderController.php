<?php

namespace Modules\Fulfillment\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Fulfillment\Models\FulfillmentOrder;
use Modules\Fulfillment\Services\OrderCaptureService;

/**
 * FUL-02 Order Capture API + FUL-03 activation completion.
 */
class FulfillmentOrderController extends ApiController
{
    public function __construct(private readonly OrderCaptureService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = FulfillmentOrder::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('accountId'), fn ($q, $a) => $q->where('account_id', $a))
            ->orderByDesc('created_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    /** POST /api/fulfillment-orders */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'string'],
            'account_id' => ['required', 'string'],
            'homepass_id' => ['nullable', 'string'],
            'package_ref' => ['required', 'string'],
            'package_version_id' => ['nullable', 'string'],
            'billing_mode' => ['nullable', 'in:POSTPAID,PREPAID'],
            'payment_ref' => ['nullable', 'string'],
            'deposit_required' => ['nullable', 'boolean'],
        ]);
        $data['created_by'] = $request->user()?->uid;

        $order = $this->orders->capture($data);

        return ApiResponse::accepted(
            entityId: $order->order_id,
            nextAction: 'AWAIT_INSTALL',
            extra: ['order' => $order, 'status' => $order->status],
            status: 201,
        );
    }

    public function show(FulfillmentOrder $fulfillmentOrder): JsonResponse
    {
        return ApiResponse::item($fulfillmentOrder->load('steps'));
    }

    /** POST /api/fulfillment-orders/{fulfillmentOrder}/complete (FUL-03 activation) */
    public function complete(FulfillmentOrder $fulfillmentOrder): JsonResponse
    {
        $order = $this->orders->complete($fulfillmentOrder);

        return ApiResponse::item($order);
    }

    public function cancel(Request $request, FulfillmentOrder $fulfillmentOrder): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return ApiResponse::item($this->orders->cancel($fulfillmentOrder, $data['reason'] ?? null));
    }
}
