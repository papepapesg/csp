<?php

namespace Modules\Catalog\Plm\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Plm\Models\Package;
use Modules\Catalog\Plm\Services\CatalogService;

/**
 * SIP-01 Package management API.
 */
class PackageController extends ApiController
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = Package::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->orderByDesc('created_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'billing_frequency_days' => ['nullable', 'integer', 'min:1'],
            'target_franchises' => ['nullable', 'array'],
            'target_tech_regions' => ['nullable', 'array'],
            'default_wallet_ref' => ['nullable', 'string', 'max:64'],
            'default_tax_group_ref' => ['nullable', 'string', 'max:64'],
        ]);

        return ApiResponse::created($this->catalog->createPackage($data));
    }

    public function show(Package $package): JsonResponse
    {
        return ApiResponse::item($package->load(['versions', 'services']));
    }

    public function update(Request $request, Package $package): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:DRAFT,ACTIVE,INACTIVE,END_OF_LIFE'],
            'target_tech_regions' => ['nullable', 'array'],
            'target_franchises' => ['nullable', 'array'],
        ]);
        $package->update($data);

        return ApiResponse::item($package);
    }

    /** POST /api/packages/{package}/versions */
    public function addVersion(Request $request, Package $package): JsonResponse
    {
        $data = $request->validate([
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'target_tech_regions' => ['nullable', 'array'],
            'target_franchises' => ['nullable', 'array'],
        ]);

        return ApiResponse::created($this->catalog->addVersion($package, $data));
    }

    /** POST /api/packages/{package}/activate */
    public function activate(Request $request, Package $package): JsonResponse
    {
        $package = $this->catalog->activatePackage($package, $request->input('versionId'));

        return ApiResponse::item($package->load('versions'));
    }
}
