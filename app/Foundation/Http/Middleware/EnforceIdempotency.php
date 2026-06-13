<?php

namespace App\Foundation\Http\Middleware;

use App\Foundation\Errors\ErrorCode;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Idempotency\IdempotencyKey;
use App\Foundation\Support\Context;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key handling for command APIs (DD_API-00 §5, §7).
 *
 * - Same key + same request hash  -> replay the stored original response.
 * - Same key + different hash     -> 409 IDEMPOTENCY_CONFLICT.
 * - New key                       -> process once, persist the response.
 *
 * Only applies to unsafe methods carrying an Idempotency-Key header; safe reads
 * pass straight through.
 */
class EnforceIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! $key || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());
        // Server-derived operator (never client header): keys are scoped per operator so the
        // same key from two tenants can't collide or replay one tenant's response to another.
        $operator = Context::operatorCode();

        $existing = IdempotencyKey::query()->where('operator_code', $operator)->where('key', $key)->first();

        if ($existing) {
            if ($existing->request_hash !== $hash) {
                return ApiResponse::error(
                    ErrorCode::IDEMPOTENCY_CONFLICT,
                    'This Idempotency-Key was already used with a different request.',
                    409,
                );
            }

            if ($existing->response_status !== null) {
                return response()->json(
                    json_decode((string) $existing->response_body, true),
                    $existing->response_status,
                )->header('Idempotent-Replay', 'true');
            }

            // In-flight duplicate that has not completed yet.
            return ApiResponse::error(
                ErrorCode::CONFLICT,
                'A request with this Idempotency-Key is still being processed.',
                409,
                retryable: true,
            );
        }

        // Reserve the key; a unique constraint protects against race conditions.
        try {
            IdempotencyKey::query()->create([
                'key' => $key,
                'operator_code' => $operator,
                'request_hash' => $hash,
            ]);
        } catch (\Throwable) {
            return ApiResponse::error(
                ErrorCode::CONFLICT,
                'A request with this Idempotency-Key is still being processed.',
                409,
                retryable: true,
            );
        }

        /** @var Response $response */
        $response = $next($request);

        // Persist the outcome for EVERY completed response, including 5xx. The business write may
        // have committed in its own transaction before a later 5xx, so releasing the key on error
        // would let a retry re-execute and double-apply money. A retry now replays the same result;
        // a caller that knows nothing committed must use a fresh key.
        DB::table('idempotency_keys')->where('operator_code', $operator)->where('key', $key)->update([
            'response_status' => $response->getStatusCode(),
            'response_body' => $response->getContent(),
            'updated_at' => now(),
        ]);

        return $response;
    }
}
