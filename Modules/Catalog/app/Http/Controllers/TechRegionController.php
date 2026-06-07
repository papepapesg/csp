<?php

namespace Modules\Catalog\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Models\TechRegion;
use Modules\Catalog\Services\CatalogService;

/**
 * ILM-CFG-02 Tech Region Registry API.
 */
class TechRegionController extends ApiController
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = TechRegion::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->boolean('activeOnly'), fn ($q) => $q->where('active', true))
            ->orderBy('tech_region_id')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tech_region_id' => ['required', 'string', 'max:64', 'unique:tech_region,tech_region_id'],
            'parent_region_id' => ['nullable', 'string', 'exists:tech_region,tech_region_id'],
            'display_name_primary' => ['required', 'string', 'max:255'],
            'display_name_secondary' => ['nullable', 'string', 'max:255'],
            'region_type' => ['required', 'in:COUNTRY,PROVINCE,CITY,NEIGHBORHOOD,CUSTOM'],
            'effective_from' => ['nullable', 'date'],
        ]);

        return ApiResponse::created($this->catalog->createTechRegion($data));
    }

    public function show(TechRegion $techRegion): JsonResponse
    {
        return ApiResponse::item($techRegion);
    }

    public function update(Request $request, TechRegion $techRegion): JsonResponse
    {
        $data = $request->validate([
            'display_name_primary' => ['sometimes', 'string', 'max:255'],
            'display_name_secondary' => ['nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'effective_to' => ['nullable', 'date'],
        ]);
        $techRegion->update($data);

        return ApiResponse::item($techRegion);
    }
}
