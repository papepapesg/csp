<?php

namespace Modules\Catalog\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Workforce\Models\Contractor;
use Modules\Catalog\Models\TechContractorSkill;
use Modules\Catalog\Models\TechRegion;
use Modules\Catalog\Services\TechCoverageService;

/** RLM-CFG-01 TechContractor / skill / region-coverage API. */
class TechCoverageController extends ApiController
{
    public function __construct(private readonly TechCoverageService $coverage) {}

    public function skills(Request $request): JsonResponse
    {
        return ApiResponse::item(['items' => TechContractorSkill::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))->orderBy('code')->get()]);
    }

    public function storeSkill(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32'], 'name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string']]);

        return ApiResponse::created($this->coverage->createSkill($data));
    }

    public function contractors(Request $request): JsonResponse
    {
        return ApiResponse::item(['items' => Contractor::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->orderBy('code')->get()]);
    }

    public function storeContractor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'], 'name' => ['required', 'string', 'max:160'],
            'skills' => ['required', 'array'], 'skills.*' => ['string'],
        ]);

        return ApiResponse::created($this->coverage->createContractor($data));
    }

    public function assignContractor(Request $request, TechRegion $techRegion): JsonResponse
    {
        $data = $request->validate(['contractor_id' => ['required', 'string'], 'skills' => ['required', 'array'], 'skills.*' => ['string']]);
        $contractor = Contractor::query()->findOrFail($data['contractor_id']);
        $this->coverage->assignContractor($techRegion, $contractor, $data['skills']);

        return ApiResponse::item(['techRegionId' => $techRegion->tech_region_id, 'contractorId' => $contractor->contractor_id, 'skills' => $data['skills']], 201);
    }

    public function activateRegion(TechRegion $techRegion): JsonResponse
    {
        return ApiResponse::item($this->coverage->activateRegion($techRegion));
    }

    public function retireRegion(TechRegion $techRegion): JsonResponse
    {
        return ApiResponse::item($this->coverage->retireRegion($techRegion));
    }

    public function retireContractor(Contractor $techContractor): JsonResponse
    {
        return ApiResponse::item($this->coverage->retireContractor($techContractor));
    }
}
