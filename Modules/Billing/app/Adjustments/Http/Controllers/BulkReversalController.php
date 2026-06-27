<?php

namespace Modules\Billing\Adjustments\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Adjustments\Services\BulkReversalService;

/** BIL-02-GEN-01 bulk reversal admin API (rule group R; dual-approved). */
class BulkReversalController extends ApiController
{
    public function __construct(private readonly BulkReversalService $reversal) {}

    /** POST /api/billing/bulk-reversals/preview */
    public function preview(Request $request): JsonResponse
    {
        return ApiResponse::item($this->reversal->preview($this->scope($request)));
    }

    /** POST /api/billing/bulk-reversals — propose (PENDING_APPROVAL). */
    public function store(Request $request): JsonResponse
    {
        $batchId = $this->reversal->propose(
            $this->scope($request),
            $request->user()?->uid ?? $request->user()?->email,
            (bool) $request->boolean('re_issue'),
            $request->input('notes'),
        );

        return ApiResponse::item(DB::table('bulk_reversal_batch')->where('batch_id', $batchId)->first(), 201);
    }

    /** GET /api/billing/bulk-reversals */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::item(['items' => DB::table('bulk_reversal_batch')
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->orderByDesc('created_at')->limit(100)->get()]);
    }

    /** POST /api/billing/bulk-reversals/{batch}/approve */
    public function approve(Request $request, string $batch): JsonResponse
    {
        return ApiResponse::item($this->reversal->approveAndExecute($batch, $request->user()));
    }

    /** POST /api/billing/bulk-reversals/{batch}/reject */
    public function reject(Request $request, string $batch): JsonResponse
    {
        $this->reversal->reject($batch, $request->user());

        return ApiResponse::item(['batch_id' => $batch, 'status' => 'REJECTED']);
    }

    private function scope(Request $request): array
    {
        return $request->validate([
            'operator_code' => ['nullable', 'string'],
            'invoice_type' => ['nullable', 'string'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'filters' => ['nullable', 'array'],
        ]);
    }
}
