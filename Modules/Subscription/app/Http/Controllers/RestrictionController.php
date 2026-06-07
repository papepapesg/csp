<?php

namespace Modules\Subscription\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Services\RestrictionService;

/**
 * SUB-WF-RESTRICT-01 trigger + read API. Unlike the MACD verb endpoints, RESTRICT
 * uses a sub-resource collection (the approved exception): restrictions are added,
 * removed, and listed independently while the subscription stays ACTIVE.
 */
class RestrictionController extends ApiController
{
    public function __construct(private readonly RestrictionService $restrictions) {}

    /** GET /api/subscriptions/{subscription}/restrictions */
    public function index(Subscription $subscription): JsonResponse
    {
        return ApiResponse::item([
            'subscriptionId' => $subscription->subscription_id,
            'activeRestrictions' => $this->restrictions->list($subscription),
        ]);
    }

    /** POST /api/subscriptions/{subscription}/restrictions (ADD) */
    public function store(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'restrictionCode' => ['required', 'string', 'max:64'],
            'activationTrigger' => ['nullable', 'string', 'max:32'],
            'dunningReference' => ['nullable', 'string', 'max:128'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $operation = $this->restrictions->add($subscription, $data['restrictionCode'], [
            'activationTrigger' => $data['activationTrigger'] ?? RestrictionService::TRIGGER_BACKOFFICE,
            'dunningReference' => $data['dunningReference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'actorUserId' => $request->user()?->uid,
            'actorRole' => $request->user()?->getRoleNames()->first(),
            'idempotencyKey' => $request->header('Idempotency-Key'),
        ]);

        return ApiResponse::accepted(
            entityId: $subscription->subscription_id,
            operationId: $operation->operation_id,
            extra: ['intent' => 'ADD', 'restrictionCode' => $data['restrictionCode']],
        );
    }

    /** DELETE /api/subscriptions/{subscription}/restrictions/{code} (REMOVE) */
    public function destroy(Request $request, Subscription $subscription, string $code): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
            'dunningOverride' => ['nullable', 'boolean'],
        ]);

        $operation = $this->restrictions->remove($subscription, $code, [
            'notes' => $data['notes'] ?? null,
            'dunningOverride' => (bool) ($data['dunningOverride'] ?? false),
            'actorUserId' => $request->user()?->uid,
            'actorRole' => $request->user()?->getRoleNames()->first(),
            'idempotencyKey' => $request->header('Idempotency-Key'),
        ]);

        return ApiResponse::accepted(
            entityId: $subscription->subscription_id,
            operationId: $operation->operation_id,
            extra: ['intent' => 'REMOVE', 'restrictionCode' => $code],
        );
    }
}
