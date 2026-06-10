<?php

namespace Modules\Billing\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Models\PaymentLedger;
use Modules\Billing\Services\PaymentService;

/**
 * BIL-01-PAY-01 payment application API.
 */
class PaymentController extends ApiController
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = PaymentLedger::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('account_id'), fn ($q, $a) => $q->where('account_id', $a))
            ->orderByDesc('received_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    /** POST /api/payments */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'string'],
            'paid_amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'in:MPESA,VISA,BANK_TRANSFER,OFFLINE'],
            'currency' => ['nullable', 'string', 'size:3'],
            'gateway_ref' => ['nullable', 'string'],
            'target_invoice_id' => ['nullable', 'string'],
        ]);

        $data['payment_reference'] = $data['gateway_ref'] ?? null;
        $payment = $this->payments->receiveAndApply($data);

        return ApiResponse::created($payment);
    }

    /** POST /api/payments/{payment}/reverse (RV; PAYMENT_REVERSAL). */
    public function reverse(Request $request, \Modules\Billing\Models\PaymentLedger $payment): JsonResponse
    {
        $data = $request->validate([
            'reason_code' => ['required', 'string', 'max:64'],
            'justification' => ['nullable', 'string', 'max:2000'],
        ]);

        return ApiResponse::item($this->payments->reverse($payment, $data['reason_code'], $request->user()?->uid ?? $request->user()?->email));
    }

    /** POST /api/payments/{payment}/allocate-surplus (OV-4 manual review release). */
    public function allocateSurplus(\Modules\Billing\Models\PaymentLedger $payment): JsonResponse
    {
        return ApiResponse::item($this->payments->allocateSurplus($payment));
    }
}
