<?php

namespace App\Foundation\Http\Controllers;

use App\Foundation\Cache\SophixCache;
use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FOUNDATION_CACHE §11 admin operations: invalidate specific keys (or
 * module/aggregate ids) and read hit/miss counters per prefix.
 */
class CacheAdminController extends ApiController
{
    public function __construct(private readonly SophixCache $cache) {}

    /** POST /api/admin/cache/invalidate — {keys:[...]} or {module, aggregate, ids:[...]}. */
    public function invalidate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keys' => ['nullable', 'array'],
            'keys.*' => ['string'],
            'module' => ['required_without:keys', 'string', 'max:32'],
            'aggregate' => ['required_with:module', 'string', 'max:64'],
            'ids' => ['required_with:module', 'array'],
            'ids.*' => ['string'],
        ]);

        $keys = $data['keys'] ?? array_map(
            fn (string $id) => SophixCache::key($data['module'], $data['aggregate'], $id),
            $data['ids'] ?? [],
        );

        return ApiResponse::item(['invalidated' => $this->cache->evictKeys($keys)]);
    }

    /** GET /api/admin/cache/stats?module=plm&aggregate=wallet */
    public function stats(Request $request): JsonResponse
    {
        $data = $request->validate([
            'module' => ['required', 'string', 'max:32'],
            'aggregate' => ['required', 'string', 'max:64'],
        ]);

        return ApiResponse::item([
            'keyPrefix' => SophixCache::key($data['module'], $data['aggregate'], ''),
        ] + $this->cache->stats($data['module'], $data['aggregate']));
    }
}
