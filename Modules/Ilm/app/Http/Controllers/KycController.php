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
    public function __construct(private readonly CustomerService $customers) {}

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

        $this->customers->recordKycDecision($customer, $level, $decision, $meta);

        return ApiResponse::item(new CustomerResource($customer->refresh()));
    }
}
