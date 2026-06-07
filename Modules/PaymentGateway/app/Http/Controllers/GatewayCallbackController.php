<?php

namespace Modules\PaymentGateway\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\PaymentGateway\Models\PaymentGatewayCallback;
use Modules\PaymentGateway\Services\GatewayCallbackService;

/**
 * PAY-GW-01 gateway callback + read API.
 */
class GatewayCallbackController extends ApiController
{
    public function __construct(private readonly GatewayCallbackService $gateway) {}

    /** POST /api/payment-gateway/{provider}/callbacks */
    public function callback(Request $request, string $provider): JsonResponse
    {
        $provider = strtoupper($provider);
        abort_unless(in_array($provider, ['MPESA', 'VISA', 'BANK_TRANSFER'], true), 404);

        $data = $request->validate([
            'external_ref' => ['required', 'string', 'max:128'],
            'account_ref' => ['nullable', 'string', 'max:128'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'raw' => ['nullable', 'array'],
        ]);

        $callback = $this->gateway->handle($provider, $data);
        $duplicate = ! $callback->wasRecentlyCreated;

        return ApiResponse::accepted(
            entityId: $callback->callback_id,
            nextAction: $callback->status === 'PROCESSED' ? 'NONE' : 'REVIEW',
            extra: [
                'callback' => $callback,
                'status' => $duplicate ? 'DUPLICATE' : $callback->status,
                'duplicate' => $duplicate,
            ],
            status: 202,
        );
    }

    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = PaymentGatewayCallback::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('provider'), fn ($q, $p) => $q->where('provider', $p))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->orderByDesc('received_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function show(PaymentGatewayCallback $paymentGatewayCallback): JsonResponse
    {
        return ApiResponse::item($paymentGatewayCallback);
    }
}
