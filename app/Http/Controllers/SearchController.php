<?php

namespace App\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use App\Services\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** FE-APP-01 §16 federated global search across the core entity types. */
class SearchController extends ApiController
{
    public function __construct(private readonly GlobalSearchService $search) {}

    public function index(Request $request): JsonResponse
    {
        $q = (string) $request->query('q', '');

        return ApiResponse::item(['query' => $q, 'groups' => $this->search->search($q, Context::operatorCode())]);
    }
}
