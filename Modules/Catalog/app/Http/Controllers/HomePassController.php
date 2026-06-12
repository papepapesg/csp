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

    /**
     * GET /api/homepass/eligible?techRegionId= — the serviceability lookup (SIP-03 coverage
     * check at acquisition). Returns HomePasses whose current status has is_sellable=true in
     * the catalog — read by the flag, never by a literal status code.
     */
    public function eligible(Request $request): JsonResponse
    {
        $operator = $request->query('operatorCode', Context::operatorCode());
        $sellableCodes = \Modules\Catalog\Models\HomePassStatusCode::query()
            ->where('operator_code', $operator)->where('is_sellable', true)->where('active', true)->pluck('code');

        $items = HomePass::query()
            ->where('operator_code', $operator)
            ->when($request->query('techRegionId'), fn ($q, $r) => $q->where('tech_region_id', $r))
            ->whereIn('status', $sellableCodes)
            ->orderByDesc('created_at')->limit(200)->get();

        return ApiResponse::item(['items' => $items, 'sellableStatuses' => $sellableCodes]);
    }

    /** PATCH /api/homepass/{homepass}/status — the code is governed by the status catalog, not an enum. */
    public function changeStatus(Request $request, HomePass $homepass): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'max:32'], // validated against homepass_status_code in the service
        ]);

        return ApiResponse::item($this->catalog->changeHomePassStatus($homepass, $data['status']));
    }
}
