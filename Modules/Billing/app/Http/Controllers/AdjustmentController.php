<?php

namespace Modules\Billing\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Models\AdjustmentReasonCode;
use Modules\Billing\Models\AdjustmentRequest;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\NoteApplication;
use Modules\Billing\Services\AdjustmentService;

/**
 * BIL-02-ADJ-01 adjustment API: propose → approve/reject/revise → apply, with
 * limit override and failed-application retry. Notes (CREDIT_NOTE / DEBIT_NOTE
 * invoices) and their application ledger are readable per note.
 */
class AdjustmentController extends ApiController
{
    public function __construct(private readonly AdjustmentService $adjustments) {}

    /** GET /api/adjustments?customer_id=&status=&reason_code= */
    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = AdjustmentRequest::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('customer_id'), fn ($q, $c) => $q->where('customer_id', $c))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('reason_code'), fn ($q, $r) => $q->where('reason_code', $r))
            ->orderByDesc('created_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    /** GET /api/adjustments/{adjustment} */
    public function show(AdjustmentRequest $adjustment): JsonResponse
    {
        return ApiResponse::item($adjustment->load(['approvalSteps', 'applications']));
    }

    /** GET /api/adjustment-reason-codes */
    public function reasonCodes(Request $request): JsonResponse
    {
        return ApiResponse::item(['items' => AdjustmentReasonCode::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->where('active', true)->orderBy('code')->get()]);
    }

    /** POST /api/adjustments */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['required', 'in:CREDIT,DEBIT'],
            'scope' => ['required', 'in:FULL,LINE,AMOUNT'],
            'parent_invoice_id' => ['nullable', 'string'],
            'line_ref' => ['nullable', 'string'],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'reason_code' => ['required', 'string'],
            'service_category_code' => ['nullable', 'string'],
            'justification' => ['nullable', 'string', 'max:2000'],
            'customer_id' => ['nullable', 'string'],
            'account_id' => ['nullable', 'string'],
            'subscription_id' => ['nullable', 'string'],
            'billing_mode' => ['nullable', 'in:POSTPAID,PREPAID'],
            'target_wallet_ref' => ['nullable', 'string'],
        ]);

        $adjustment = $this->adjustments->propose($data, $request->user()?->uid ?? $request->user()?->email);

        return ApiResponse::item($adjustment->load('approvalSteps'), 201);
    }

    /** POST /api/adjustments/{adjustment}/approve */
    public function approve(Request $request, AdjustmentRequest $adjustment): JsonResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        return ApiResponse::item($this->adjustments->approve($adjustment, $this->actor($request), $data['comment'] ?? null)->load(['approvalSteps', 'applications']));
    }

    /** POST /api/adjustments/{adjustment}/reject */
    public function reject(Request $request, AdjustmentRequest $adjustment): JsonResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        return ApiResponse::item($this->adjustments->reject($adjustment, $this->actor($request), $data['comment'] ?? null));
    }

    /** POST /api/adjustments/{adjustment}/request-revision */
    public function requestRevision(Request $request, AdjustmentRequest $adjustment): JsonResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        return ApiResponse::item($this->adjustments->requestRevision($adjustment, $this->actor($request), $data['comment'] ?? null));
    }

    /** POST /api/adjustments/{adjustment}/cancel */
    public function cancel(AdjustmentRequest $adjustment): JsonResponse
    {
        return ApiResponse::item($this->adjustments->cancel($adjustment));
    }

    /** POST /api/adjustments/{adjustment}/override-limit */
    public function overrideLimit(Request $request, AdjustmentRequest $adjustment): JsonResponse
    {
        return ApiResponse::item($this->adjustments->overrideLimit($adjustment, $this->actor($request)));
    }

    /** POST /api/adjustments/{adjustment}/retry-application */
    public function retryApplication(AdjustmentRequest $adjustment): JsonResponse
    {
        return ApiResponse::item($this->adjustments->retryApplication($adjustment)->load('applications'));
    }

    /** GET /api/credit-notes/{note} — the note document + its application ledger. */
    public function showNote(string $note): JsonResponse
    {
        $invoice = Invoice::query()->whereKey($note)
            ->whereIn('type', [Invoice::CREDIT_NOTE, Invoice::DEBIT_NOTE])
            ->firstOrFail();

        return ApiResponse::item([
            'note' => $invoice->load('lines'),
            'applications' => NoteApplication::query()->where('note_id', $invoice->invoice_id)->orderBy('applied_at')->get(),
        ]);
    }

    private function actor(Request $request): ?string
    {
        return $request->user()?->uid ?? $request->user()?->email;
    }
}
