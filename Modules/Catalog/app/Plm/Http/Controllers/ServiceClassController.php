<?php

namespace Modules\Catalog\Plm\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Plm\Models\ServiceClass;

/**
 * PLM-CFG-01 Service class API (reference data for services).
 */
class ServiceClassController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = ServiceClass::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->orderBy('name')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'requires_equipment' => ['sometimes', 'boolean'],
            'default_tax_group_ref' => ['nullable', 'string', 'max:64'],
            'default_wallet_ref' => ['nullable', 'string', 'max:64'],
        ]);

        return ApiResponse::created(ServiceClass::query()->create($data));
    }

    public function show(ServiceClass $serviceClass): JsonResponse
    {
        return ApiResponse::item($serviceClass);
    }
}
