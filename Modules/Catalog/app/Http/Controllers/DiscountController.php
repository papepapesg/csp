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
use Modules\Catalog\Services\DiscountComputeService;

/** PLM-CFG-04 discount catalog + SIP-03 assignment + DIS-OP-01 compute. */
class DiscountController extends ApiController
{
    public function __construct(private readonly DiscountComputeService $discounts) {}

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
}
