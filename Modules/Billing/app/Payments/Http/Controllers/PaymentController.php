<?php

namespace Modules\Billing\Payments\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Payments\Models\PaymentLedger;
use Modules\Billing\Payments\Services\PaymentService;

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
            ->with('allocations')
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('account_id'), fn ($q, $a) => $q->where('account_id', $a))
            ->orderByDesc('received_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    /** GET /api/payments/{payment} — the ledger row with its invoice allocation breakdown. */
    public function show(PaymentLedger $payment): JsonResponse
    {
        return ApiResponse::item($payment->load('allocations'));
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

        // RC-3 dedup reference: a gateway ref for online, else the Idempotency-Key for a
        // reference-less (OFFLINE/cash) payment. A payment with NEITHER cannot be made
        // idempotent, so it is rejected — a retried cash POST must never double-apply money.
        $data['payment_reference'] = ($data['gateway_ref'] ?? null) ?: $request->header('Idempotency-Key');
        if (! $data['payment_reference']) {
            return ApiResponse::error('PAYMENT_REFERENCE_REQUIRED', 'A payment must carry a gateway_ref or an Idempotency-Key for idempotent application.', 422);
        }
        $payment = $this->payments->receiveAndApply($data);

        return ApiResponse::created($payment);
    }

    /** POST /api/payments/{payment}/reverse (RV; PAYMENT_REVERSAL). */
    public function reverse(Request $request, PaymentLedger $payment): JsonResponse
    {
        $data = $request->validate([
            'reason_code' => ['required', 'string', 'max:64'],
            'justification' => ['nullable', 'string', 'max:2000'],
        ]);

        return ApiResponse::item($this->payments->reverse($payment, $data['reason_code'], $request->user()?->uid ?? $request->user()?->email));
    }

    /** POST /api/payments/{payment}/allocate-surplus (OV-4 manual review release). */
    public function allocateSurplus(PaymentLedger $payment): JsonResponse
    {
        return ApiResponse::item($this->payments->allocateSurplus($payment));
    }
}
