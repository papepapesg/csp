<?php

namespace Modules\Subscription\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Models\SubscriptionOperation;
use Modules\Subscription\Services\OperationFramework;

/**
 * SUB-WF operation trigger + tracking API. Commands return ACCEPTED + an
 * operation id and status URL (DD_API-00 §7).
 */
class OperationController extends ApiController
{
    public function __construct(private readonly OperationFramework $framework) {}

    /** POST /api/subscriptions/{subscription}/activate */
    public function activate(Request $request, Subscription $subscription): JsonResponse
    {
        $request->validate(['recipient' => ['nullable', 'string', 'max:32']]);

        return $this->trigger($request, $subscription, 'ACTIVATE', $request->only('recipient'));
    }

    /** POST /api/subscriptions/{subscription}/pause */
    public function pause(Request $request, Subscription $subscription): JsonResponse
    {
        $request->validate(['reasonCode' => ['nullable', 'string', 'max:64']]);

        return $this->trigger($request, $subscription, 'PAUSE', $request->only('reasonCode'));
    }

    /** POST /api/subscriptions/{subscription}/resume */
    public function resume(Request $request, Subscription $subscription): JsonResponse
    {
        return $this->trigger($request, $subscription, 'RESUME');
    }

    /** POST /api/subscriptions/{subscription}/terminate */
    public function terminate(Request $request, Subscription $subscription): JsonResponse
    {
        $request->validate(['reasonCode' => ['nullable', 'string', 'max:64']]);

        return $this->trigger($request, $subscription, 'TERMINATE', $request->only('reasonCode'));
    }

    /** GET /api/subscriptions/{subscription}/operations */
    public function index(Subscription $subscription): JsonResponse
    {
        return ApiResponse::item([
            'items' => $subscription->operations()->orderByDesc('created_at')->limit(50)->get(),
        ]);
    }

    /** GET /api/subscriptions/{subscription}/operations/{operation} (status URL) */
    public function show(Subscription $subscription, SubscriptionOperation $operation): JsonResponse
    {
        return ApiResponse::item($operation);
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function trigger(Request $request, Subscription $subscription, string $kind, array $input = []): JsonResponse
    {
        $operation = $this->framework->trigger(
            subscription: $subscription,
            kind: $kind,
            input: $input,
            idempotencyKey: $request->header('Idempotency-Key'),
            actorUserId: $request->user()?->uid,
            actorRole: $request->user()?->getRoleNames()->first(),
        );

        return ApiResponse::accepted(
            entityId: $subscription->subscription_id,
            operationId: $operation->operation_id,
            nextAction: 'TRACK_OPERATION',
            extra: [
                'statusUrl' => "/api/subscriptions/{$subscription->subscription_id}/operations/{$operation->operation_id}",
            ],
        );
    }
}
