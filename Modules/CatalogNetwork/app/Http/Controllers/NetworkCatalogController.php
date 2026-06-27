<?php

namespace Modules\Catalog\Network\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Network\Models\HouseType;
use Modules\Catalog\Network\Models\NetworkNode;
use Modules\Catalog\Network\Services\NetworkCatalogService;

/** RLM-CFG-01 network_node + house_type reference catalogs. */
class NetworkCatalogController extends ApiController
{
    public function __construct(private readonly NetworkCatalogService $network) {}

    public function nodes(Request $request): JsonResponse
    {
        return ApiResponse::item(['items' => NetworkNode::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))->orderBy('code')->get()]);
    }

    public function storeNode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'type' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:160'],
            'parent_node_code' => ['nullable', 'string', 'max:64'],
            'metadata' => ['nullable', 'array'],
        ]);

        return ApiResponse::created($this->network->createNode($data));
    }

    public function retireNode(NetworkNode $networkNode): JsonResponse
    {
        return ApiResponse::item($this->network->retireNode($networkNode));
    }

    public function houseTypes(Request $request): JsonResponse
    {
        return ApiResponse::item(['items' => HouseType::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))->orderBy('display_order')->get()]);
    }

    public function storeHouseType(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'description' => ['required', 'string', 'max:160'],
            'display_order' => ['nullable', 'integer'],
        ]);

        return ApiResponse::created(HouseType::query()->create($data + ['operator_code' => Context::operatorCode(), 'status' => 'ACTIVE']));
    }
}
