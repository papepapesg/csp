<?php

namespace Modules\Catalog\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Models\Service;
use Modules\Catalog\Services\CatalogPolicy;
use Modules\Catalog\Services\CatalogService;

/**
 * PLM-CFG-01 Service catalog API.
 */
class ServiceController extends ApiController
{
    public function __construct(
        private readonly CatalogService $catalog,
        private readonly CatalogPolicy $policy,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = Service::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('code')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64'],
            'service_class_id' => ['required', 'string', 'exists:service_class,id'],
            'description' => ['nullable', 'string'],
            'consumption_model' => ['nullable', 'in:FLAT,USAGE,TIERED'],
            'is_addressable' => ['sometimes', 'boolean'],
            'equipment_requirement_ref' => ['nullable', 'string', 'max:64'],
            'service_group' => ['nullable', 'string', 'max:64'],
            'network_profile_shape' => ['nullable', 'array'],
            'default_wallet_ref' => ['nullable', 'string', 'max:64'],
            'default_tax_group_ref' => ['nullable', 'string', 'max:64'],
        ]);

        $service = $this->catalog->createService($data);

        // rules.service-catalog config policy (advisory).
        return ApiResponse::created(['service' => $service, 'policyWarnings' => $this->policy->validateService($service)]);
    }

    public function show(Service $service): JsonResponse
    {
        return ApiResponse::item($service->load('serviceClass'));
    }

    public function update(Request $request, Service $service): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE,RETIRED'],
            'network_profile_shape' => ['nullable', 'array'],
        ]);
        $service->update($data);

        return ApiResponse::item($service);
    }
}
