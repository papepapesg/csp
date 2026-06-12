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
        private readonly \Modules\Catalog\Services\HomePassTopologyService $topology,
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
            'house_type_code' => ['nullable', 'string', 'max:32'],
            // Structured address hierarchy (H-1) + rich fields.
            'country' => ['nullable', 'string', 'max:8'],
            'region' => ['nullable', 'string'], 'region_l1' => ['nullable', 'string'], 'region_l2' => ['nullable', 'string'],
            'city' => ['nullable', 'string'], 'area' => ['nullable', 'string'],
            'sub_area_1' => ['nullable', 'string'], 'sub_area_2' => ['nullable', 'string'],
            'road_name' => ['nullable', 'string'], 'building_number' => ['nullable', 'string'],
            'building_name' => ['nullable', 'string'], 'apartment_number' => ['nullable', 'string'],
            'property_type' => ['nullable', 'in:RES,COM,MIXED,OTHER,TST'],
            'latitude' => ['nullable', 'numeric'], 'longitude' => ['nullable', 'numeric'],
            'google_place_id' => ['nullable', 'string', 'max:256'],
            'roe_signed_date' => ['nullable', 'date'], 'not_serviceable_reason' => ['nullable', 'string', 'max:64'],
        ]);

        $homepass = $this->catalog->createHomePass($data);

        // rules.homepass-catalog config policy (advisory).
        return ApiResponse::created(['homepass' => $homepass, 'policyWarnings' => $this->policy->validateHomePass($homepass)]);
    }

    /** PATCH /api/homepass/{homepass}/address — correct address fields (H-14 audit, H-18 immutable place_id). */
    public function correctAddress(Request $request, HomePass $homepass): JsonResponse
    {
        $data = $request->validate([
            'region' => ['nullable', 'string'], 'region_l1' => ['nullable', 'string'], 'region_l2' => ['nullable', 'string'],
            'city' => ['nullable', 'string'], 'area' => ['nullable', 'string'],
            'sub_area_1' => ['nullable', 'string'], 'sub_area_2' => ['nullable', 'string'],
            'road_name' => ['nullable', 'string'], 'building_number' => ['nullable', 'string'],
            'building_name' => ['nullable', 'string'], 'apartment_number' => ['nullable', 'string'],
            'latitude' => ['nullable', 'numeric'], 'longitude' => ['nullable', 'numeric'],
            'google_place_id' => ['nullable', 'string', 'max:256'], 'directions' => ['nullable', 'string'],
        ]);

        return ApiResponse::item($this->catalog->correctAddress($homepass, array_filter($data, fn ($v) => $v !== null)));
    }

    public function show(HomePass $homepass): JsonResponse
    {
        return ApiResponse::item($homepass);
    }

    /** POST /api/homepass/bulk-import — partial-reject by default; mode=all-or-nothing aborts on first failure. */
    public function bulkImport(Request $request): JsonResponse
    {
        $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.address' => ['required', 'string'],
            'mode' => ['nullable', 'in:partial,all-or-nothing'],
        ]);
        // Pass the raw rows (not the validated subset) so structured-address fields survive.
        return ApiResponse::item($this->catalog->bulkImportHomePasses($request->input('rows'), $request->input('mode', 'partial')));
    }

    /** POST /api/homepass/{homepass}/enrich-from-geo — reverse-geocode + fill admin fields (no-op when GIS off). */
    public function enrichFromGeo(HomePass $homepass): JsonResponse
    {
        return ApiResponse::item((new \Modules\Catalog\Services\Geo\GeoClient())->enrichFromGeo($homepass));
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

    /**
     * PATCH /api/homepass/{homepass}/network-path — set the node chain; the service re-derives
     * services_supported + service_management_endpoints (RLM-CFG-01 §network_path).
     */
    public function setNetworkPath(Request $request, HomePass $homepass): JsonResponse
    {
        $v = $request->validate([
            'captureMode' => ['nullable', 'in:PRE_INSTALLATION,AT_INSTALLATION'],
            'nodes' => ['required', 'array'],
            'nodes.*.type' => ['required', 'string'],
            'nodes.*.code' => ['required', 'string'],
            'nodes.*.role' => ['nullable', 'in:service_management,passive,termination'],
            'nodes.*.port' => ['nullable', 'string'],
        ]);

        return ApiResponse::item($this->topology->setNetworkPath($homepass, $v));
    }

    /** GET /api/homepass/{homepass}/eligible-contractors?skill=INSTALLATION (WO routing primitive). */
    public function eligibleContractors(Request $request, HomePass $homepass): JsonResponse
    {
        $skill = $request->validate(['skill' => ['required', 'string']])['skill'];

        return ApiResponse::item(['items' => $this->topology->eligibleContractors($homepass, $skill)]);
    }

    /** PATCH /api/homepass/{homepass}/status — the code is governed by the status catalog, not an enum. */
    public function changeStatus(Request $request, HomePass $homepass): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'max:32'], // validated against homepass_status_code in the service
        ]);

        return ApiResponse::item($this->catalog->changeHomePassStatus($homepass, $data['status'], $request->user()?->uid));
    }
}
