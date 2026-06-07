<?php

namespace Modules\Catalog\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Services\TaxComputeService;

/** PLM-CFG-02 tax compute API. Stateless: returns the tax breakdown for a charge. */
class TaxController extends ApiController
{
    public function __construct(private readonly TaxComputeService $tax) {}

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
}
