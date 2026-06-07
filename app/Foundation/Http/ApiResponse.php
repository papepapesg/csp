<?php

namespace App\Foundation\Http;

use App\Foundation\Support\Context;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * Builders for the SOPHIX standard response envelopes (DD_API-00 §6, §7, §8).
 *
 * Keeping these in one place guarantees every module returns the same shapes for
 * reads (paginated), commands (ACCEPTED + operation id) and errors.
 */
final class ApiResponse
{
    /** A single resource / read result. */
    public static function item(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)
            ->header('X-Correlation-Id', Context::correlationId());
    }

    /**
     * Paginated list response (DD_API-00 §6).
     *
     * The on-the-wire shape is: { items, page, size, totalElements, totalPages }.
     * Note: SOPHIX pages are zero-based; Laravel paginators are one-based.
     */
    public static function paginated(LengthAwarePaginator $paginator, ?callable $map = null): JsonResponse
    {
        $items = $paginator->getCollection();
        if ($map) {
            $items = $items->map($map);
        }

        return response()->json([
            'items' => $items->values(),
            'page' => $paginator->currentPage() - 1,
            'size' => $paginator->perPage(),
            'totalElements' => $paginator->total(),
            'totalPages' => $paginator->lastPage(),
        ])->header('X-Correlation-Id', Context::correlationId());
    }

    /**
     * Command acknowledgement (DD_API-00 §7).
     *
     * @param  array<string, mixed>  $extra
     */
    public static function accepted(
        ?string $entityId = null,
        ?string $operationId = null,
        string $nextAction = 'TRACK_OPERATION',
        array $extra = [],
        int $status = 202,
    ): JsonResponse {
        $payload = array_filter([
            'status' => 'ACCEPTED',
            'entityId' => $entityId,
            'operationId' => $operationId,
            'correlationId' => Context::correlationId(),
            'nextAction' => $nextAction,
        ], static fn ($v) => $v !== null);

        return response()->json(array_merge($payload, $extra), $status)
            ->header('X-Correlation-Id', Context::correlationId());
    }

    /** Resource created synchronously (HTTP 201). */
    public static function created(mixed $data): JsonResponse
    {
        return self::item($data, 201);
    }

    /**
     * Standard error envelope (DD_API-00 §8).
     *
     * @param  array<int, array{field: string, code: string, message: string}>  $fieldErrors
     */
    public static function error(
        string $errorCode,
        string $message,
        int $status = 422,
        bool $retryable = false,
        array $fieldErrors = [],
        ?string $nextAction = null,
    ): JsonResponse {
        return response()->json(array_filter([
            'errorCode' => $errorCode,
            'message' => $message,
            'correlationId' => Context::correlationId(),
            'retryable' => $retryable,
            'fieldErrors' => $fieldErrors ?: null,
            'nextAction' => $nextAction,
        ], static fn ($v) => $v !== null), $status)
            ->header('X-Correlation-Id', Context::correlationId());
    }
}
