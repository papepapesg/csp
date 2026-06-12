<?php

namespace Modules\Catalog\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Models\Discount;
use Modules\Catalog\Models\DiscountAssignment;
use Modules\Catalog\Services\DiscountAssignmentService;
use Modules\Catalog\Services\DiscountComputeService;

/** PLM-CFG-04 discount catalog + SIP-03 assignment lifecycle + DIS-OP-01 compute. */
class DiscountController extends ApiController
{
    public function __construct(
        private readonly DiscountComputeService $discounts,
        private readonly DiscountAssignmentService $assignments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::item(['items' => Discount::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))->orderBy('priority')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:48'],
            'name' => ['required', 'string', 'max:120'],
            'discount_type' => ['required', 'in:PERCENT,FIXED'],
            'value' => ['required', 'numeric', 'min:0'],
            'applies_to' => ['nullable', 'in:INVOICE,PACKAGE,SERVICE'],
            'stackable' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer'],
        ]);

        return ApiResponse::created(Discount::query()->create($data + ['discount_id' => Id::make('disc')]));
    }

    /** POST /api/discounts/assign */
    public function assign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'discount_code' => ['required', 'string'],
            'scope' => ['required', 'in:CUSTOMER,SUBSCRIPTION,PACKAGE,CAMPAIGN,ALL'],
            'scope_ref' => ['nullable', 'string'],
            'campaign_code' => ['nullable', 'string'],
        ]);

        return ApiResponse::created(DiscountAssignment::query()->create($data + ['assignment_id' => Id::make('dasg')]));
    }

    /** POST /api/discounts/compute */
    public function compute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'baseAmount' => ['required', 'numeric', 'min:0'],
            'customerId' => ['nullable', 'string'],
            'subscriptionId' => ['nullable', 'string'],
            'packageRef' => ['nullable', 'string'],
            'campaignCode' => ['nullable', 'string'],
        ]);
        $operator = $request->input('operatorCode', Context::operatorCode());

        return ApiResponse::item($this->discounts->compute($operator, (float) $data['baseAmount'], $data));
    }

    // ---- SIP-03 assignment lifecycle ----

    /** @return array<string,mixed> */
    private function assignmentRules(): array
    {
        return [
            'operatorCode' => ['nullable', 'string'],
            'discountCode' => ['required', 'string'],
            'scopeType' => ['required', 'in:CUSTOMER,ACCOUNT,SUBSCRIPTION,ORDER,PACKAGE,FRANCHISE,CAMPAIGN_COHORT'],
            'scopeRefId' => ['nullable', 'string'],
            'customerId' => ['nullable', 'string'], 'accountId' => ['nullable', 'string'], 'subscriptionId' => ['nullable', 'string'],
            'packageRef' => ['nullable', 'string'], 'campaignId' => ['nullable', 'string'], 'franchiseId' => ['nullable', 'string'],
            'reasonCode' => ['nullable', 'string'], 'sourceChannel' => ['nullable', 'in:BACKOFFICE,SALES_APP,CAMPAIGN,ASR,BATCH,API'],
            'validFrom' => ['nullable', 'date'], 'validTo' => ['nullable', 'date'],
            'priority' => ['nullable', 'integer'], 'stackingGroupCode' => ['nullable', 'string'],
            'estimatedValue' => ['nullable', 'numeric'], 'metadata' => ['nullable', 'array'],
        ];
    }

    /** POST /api/discount-assignments — create a governed assignment (SIP-03). */
    public function createAssignment(Request $request): JsonResponse
    {
        $data = $request->validate($this->assignmentRules());
        $data['createdByUserId'] = $request->user()?->uid;
        $result = $this->assignments->create($data);

        return ApiResponse::created([
            'assignmentId' => $result['assignment']->assignment_id,
            'status' => $result['assignment']->status,
            'approvalRequired' => $result['approvalRequired'],
        ]);
    }

    /** POST /api/discount-assignments/preview — eligibility + whether approval is required. */
    public function previewAssignment(Request $request): JsonResponse
    {
        $data = $request->validate($this->assignmentRules());
        $result = $this->assignments->create($data, previewOnly: true);

        return ApiResponse::item(['eligible' => $result['eligible'], 'approvalRequired' => $result['approvalRequired'], 'reason' => $result['reason']]);
    }

    /** GET /api/discount-assignments/effective — runtime query for DIS-OP-01/BIL. */
    public function effective(Request $request): JsonResponse
    {
        $operator = $request->query('operatorCode', Context::operatorCode());
        $items = $this->assignments->effective($operator, [
            'subscriptionId' => $request->query('subscriptionId'), 'customerId' => $request->query('customerId'),
        ], $request->query('onDate'));

        return ApiResponse::item(['assignments' => $items]);
    }

    /** POST /api/discount-assignments/{discountAssignment}/cancel — stop future runtime (R-DA-08). */
    public function cancelAssignment(Request $request, DiscountAssignment $discountAssignment): JsonResponse
    {
        $data = $request->validate(['reasonCode' => ['required', 'string'], 'comment' => ['nullable', 'string']]);

        return ApiResponse::item($this->assignments->cancel($discountAssignment, $data['reasonCode'], $request->user()?->uid));
    }

    /** POST /api/discount-assignments/{discountAssignment}/approval-outcome — EM-CFG-04 callback (R-DA-11). */
    public function approvalOutcome(Request $request, DiscountAssignment $discountAssignment): JsonResponse
    {
        $data = $request->validate(['outcome' => ['required', 'in:APPROVED,REJECTED'], 'decisionComment' => ['nullable', 'string']]);

        return ApiResponse::item($this->assignments->applyApprovalOutcome($discountAssignment, $data['outcome'], $request->user()?->uid));
    }
}
