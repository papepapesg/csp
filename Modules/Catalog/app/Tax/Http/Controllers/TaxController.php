<?php

namespace Modules\Catalog\Tax\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Tax\Models\TaxGroup;
use Modules\Catalog\Tax\Models\TaxRule;
use Modules\Catalog\Tax\Services\TaxComputeService;
use Modules\Catalog\Tax\Services\TaxConfigService;

/**
 * PLM-CFG-02 tax API. compute() is stateless (tax breakdown for a charge); the
 * rules/groups endpoints are the operator admin surface — effective-dated rule
 * versions and ordered groups, every write emitting TaxConfigChanged.
 */
class TaxController extends ApiController
{
    public function __construct(
        private readonly TaxComputeService $tax,
        private readonly TaxConfigService $config,
    ) {}

    /** POST /api/tax/compute */
    public function compute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operatorCode' => ['required', 'string'],
            'taxableKind' => ['nullable', 'string'],
            'taxableRef' => ['nullable', 'string'],
            'baseAmount' => ['required', 'numeric'],
            'currency' => ['nullable', 'string', 'size:3'],
            'customerCategory' => ['nullable', 'string'],
            'taxableAt' => ['nullable', 'date'],
        ]);

        return ApiResponse::item($this->tax->compute($data));
    }

    /** GET /api/tax/rules — every version (incl. superseded) for the operator. */
    public function rules(Request $request): JsonResponse
    {
        $rules = TaxRule::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('code'), fn ($q, $c) => $q->where('code', $c))
            ->orderBy('code')->orderByDesc('effective_from')->get();

        return ApiResponse::item(['items' => $rules]);
    }

    public function storeRule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:120'],
            'taxable_category' => ['nullable', 'string', 'max:64'],
            'rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'base_method' => ['nullable', 'in:BASE,BASE_PLUS_PRIOR'],
            'order_within_group' => ['nullable', 'integer', 'min:1'],
            'rounding_mode' => ['nullable', 'string', 'max:16'],
            'rounding_scale' => ['nullable', 'integer', 'min:0', 'max:6'],
            'regulator' => ['nullable', 'string', 'max:32'],
            'regulator_tax_code' => ['nullable', 'string', 'max:64'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date'],
        ]);

        return ApiResponse::created($this->config->createRule($data));
    }

    public function updateRule(Request $request, TaxRule $taxRule): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'base_method' => ['sometimes', 'in:BASE,BASE_PLUS_PRIOR'],
            'order_within_group' => ['sometimes', 'integer', 'min:1'],
            'rounding_mode' => ['sometimes', 'string', 'max:16'],
            'rounding_scale' => ['sometimes', 'integer', 'min:0', 'max:6'],
            'regulator' => ['sometimes', 'nullable', 'string', 'max:32'],
            'regulator_tax_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'effective_until' => ['sometimes', 'nullable', 'date'],
        ]);

        return ApiResponse::item($this->config->updateRule($taxRule, $data));
    }

    public function groups(Request $request): JsonResponse
    {
        $groups = TaxGroup::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->orderBy('code')->get();

        return ApiResponse::item(['items' => $groups]);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:120'],
            'order_within_group' => ['required', 'array', 'min:1'],
            'order_within_group.*' => ['string'],
            'regulator_reference' => ['nullable', 'string', 'max:64'],
        ]);

        return ApiResponse::created($this->config->createGroup($data));
    }

    public function updateGroup(Request $request, TaxGroup $taxGroup): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'order_within_group' => ['sometimes', 'array', 'min:1'],
            'order_within_group.*' => ['string'],
            'regulator_reference' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        return ApiResponse::item($this->config->updateGroup($taxGroup, $data));
    }
}
