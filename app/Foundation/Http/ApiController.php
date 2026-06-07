<?php

namespace App\Foundation\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Base controller for module API controllers. Centralises pagination input
 * parsing so every list endpoint honours DD_API-00 §6 (zero-based page, bounded
 * size, sort=field,dir).
 */
abstract class ApiController extends Controller
{
    /**
     * @return array{page: int, size: int, sort: array{0: string, 1: string}|null}
     */
    protected function pageParams(Request $request): array
    {
        $size = (int) $request->integer('size', (int) config('sophix.pagination.default_size', 50));
        $size = max(1, min($size, (int) config('sophix.pagination.max_size', 200)));

        $sort = null;
        if ($raw = $request->query('sort')) {
            [$field, $dir] = array_pad(explode(',', (string) $raw, 2), 2, 'asc');
            $sort = [$field, strtolower($dir) === 'desc' ? 'desc' : 'asc'];
        }

        return [
            'page' => max(0, (int) $request->integer('page', 0)),
            'size' => $size,
            'sort' => $sort,
        ];
    }
}
