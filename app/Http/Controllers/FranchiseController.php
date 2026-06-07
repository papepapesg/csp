<?php

namespace App\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use App\Models\Franchise;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** EM-01 Franchise Management API. */
class FranchiseController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::item(['items' => Franchise::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))->orderBy('code')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:48'],
            'name' => ['required', 'string', 'max:120'],
            'territory' => ['nullable', 'string'],
            'owner_name' => ['nullable', 'string'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        return ApiResponse::created(Franchise::query()->create($data + ['franchise_id' => Id::make('frn')]));
    }
}
