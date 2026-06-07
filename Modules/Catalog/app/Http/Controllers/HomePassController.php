<?php

namespace Modules\Catalog\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Models\HomePass;
use Modules\Catalog\Services\CatalogPolicy;
use Modules\Catalog\Services\CatalogService;

/**
 * RLM-CFG-01 HomePass configuration / serviceability API.
 */
class HomePassController extends ApiController
{
    public function __construct(
        private readonly CatalogService $catalog,
        private readonly CatalogPolicy $policy,
    ) {}

    /** GET /api/homepass?techRegionId=&status=&q= (serviceability search) */
    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = HomePass::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('techRegionId'), fn ($q, $r) => $q->where('tech_region_id', $r))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('q'), fn ($q, $term) => $q->where('address', 'like', "%{$term}%"))
            ->orderByDesc('created_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:64'],
            'address' => ['required', 'string', 'max:512'],
            'tech_region_id' => ['nullable', 'string', 'exists:tech_region,tech_region_id'],
            'technology' => ['nullable', 'string', 'max:32'],
            'network_nodes' => ['nullable', 'array'],
        ]);

        $homepass = $this->catalog->createHomePass($data);

        // rules.homepass-catalog config policy (advisory).
        return ApiResponse::created(['homepass' => $homepass, 'policyWarnings' => $this->policy->validateHomePass($homepass)]);
    }

    public function show(HomePass $homepass): JsonResponse
    {
        return ApiResponse::item($homepass);
    }

    /** PATCH /api/homepass/{homepass}/status */
    public function changeStatus(Request $request, HomePass $homepass): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:DRAFT,SERVICEABLE,RESERVED,RETIRED'],
        ]);

        return ApiResponse::item($this->catalog->changeHomePassStatus($homepass, $data['status']));
    }
}
