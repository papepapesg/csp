<?php

namespace Modules\Ilm\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ilm\Http\Resources\CustomerResource;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Services\CustomerService;

/**
 * KYC decision endpoints (ILM-CFG-01 §KYC). The kyc_status state machine is
 * advanced by CustomerService::recordKycDecision().
 */
class KycController extends ApiController
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly \App\Foundation\Files\FileStorageService $files,
    ) {}

    /**
     * POST /api/customers/{customer}/kyc/documents — capture a KYC document. Post the
     * binary (multipart) and we store it in FOUNDATION_FILE_STORAGE here, or reference a
     * file already uploaded via POST /api/files by its file_id. ILM keeps only the reference.
     */
    public function storeDocument(Request $request, Customer $customer): JsonResponse
    {
        $v = $request->validate([
            'file' => ['required_without:file_id', 'file', 'max:20480'],
            'file_id' => ['required_without:file', 'string'],
            'document_type' => ['required', 'string', 'max:64'],
            'document_name' => ['nullable', 'string', 'max:160'],
        ]);

        if ($request->hasFile('file')) {
            $object = $this->files->store($request->file('file'), [
                'owner_type' => 'CUSTOMER_KYC', 'owner_id' => $customer->customer_id,
                'category' => 'kyc', 'uploaded_by' => $request->user()?->uid,
            ]);
            $v['file_id'] = $object->file_id;
        }

        return ApiResponse::item($this->customers->recordKycDocument($customer, $v, $request->user()?->uid), 201);
    }

    /** POST /api/customers/{customer}/kyc/l1-approve */
    public function l1Approve(Request $request, Customer $customer): JsonResponse
    {
        return $this->decide($request, $customer, 1, 'APPROVED');
    }

    /** POST /api/customers/{customer}/kyc/final-approve */
    public function finalApprove(Request $request, Customer $customer): JsonResponse
    {
        return $this->decide($request, $customer, 2, 'APPROVED');
    }

    /** POST /api/customers/{customer}/kyc/reject */
    public function reject(Request $request, Customer $customer): JsonResponse
    {
        return $this->decide($request, $customer, (int) $request->input('level', 1), 'REJECTED');
    }

    private function decide(Request $request, Customer $customer, int $level, string $decision): JsonResponse
    {
        $meta = $request->validate([
            'approverId' => ['nullable', 'string', 'max:128'],
            'approverRole' => ['nullable', 'string', 'max:64'],
            'approvalLevelName' => ['nullable', 'string', 'max:64'],
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);
        $meta['actor'] = $request->user(); // R-ILM-K-3 authority check

        $this->customers->recordKycDecision($customer, $level, $decision, $meta);

        return ApiResponse::item(new CustomerResource($customer->refresh()));
    }
}
