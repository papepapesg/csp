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

    /** POST /api/subscriptions/{subscription}/upgrade */
    public function upgrade(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'targetPackageRef' => ['required', 'string', 'max:64'],
            'effectiveTiming' => ['nullable', 'in:IMMEDIATE,END_OF_CURRENT_CYCLE,SCHEDULED_AT'],
            'cycleAnchorPolicy' => ['nullable', 'in:PRESERVE,RESET_TO_UPGRADE_DATE'],
        ]);

        return $this->trigger($request, $subscription, 'UPGRADE', $data);
    }

    /** POST /api/subscriptions/{subscription}/downgrade */
    public function downgrade(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'targetPackageRef' => ['required', 'string', 'max:64'],
            'effectiveTiming' => ['nullable', 'in:IMMEDIATE,END_OF_CURRENT_CYCLE,SCHEDULED_AT'],
            'cycleAnchorPolicy' => ['nullable', 'in:PRESERVE,RESET_TO_UPGRADE_DATE'],
        ]);

        return $this->trigger($request, $subscription, 'DOWNGRADE', $data);
    }

    /** POST /api/subscriptions/{subscription}/relocate */
    public function relocate(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'targetHomepassId' => ['required', 'string', 'max:64'],
            'relocationTrigger' => ['nullable', 'string', 'max:48'],
            'effectiveTiming' => ['nullable', 'in:IMMEDIATE,END_OF_CURRENT_CYCLE,SCHEDULED_AT'],
        ]);

        return $this->trigger($request, $subscription, 'RELOCATION', $data);
    }

    /** POST /api/subscriptions/{subscription}/migrate */
    public function migrate(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'targetHomepassId' => ['required', 'string', 'max:64'],
            'targetPackageRef' => ['nullable', 'string', 'max:64'],
            'effectiveTiming' => ['nullable', 'in:IMMEDIATE,END_OF_CURRENT_CYCLE,SCHEDULED_AT'],
        ]);

        return $this->trigger($request, $subscription, 'MIGRATION', $data);
    }

    /**
     * POST /api/subscriptions/{subscription}/suspend-np
     *
     * DD_SUB-WF-SUSPEND-NP-01: system-driven non-payment suspension. R-T-1 gates the
     * trigger to the BILLING_INTERNAL role only (BIL-04's service account) — any other
     * actor gets 403 UNAUTHORIZED_TRIGGER. R-T-2 requires the dunning context.
     */
    public function suspendNp(Request $request, Subscription $subscription): JsonResponse
    {
        if (! $request->user()?->hasRole('BILLING_INTERNAL')) {
            throw new \App\Foundation\Errors\DomainException(
                'UNAUTHORIZED_TRIGGER',
                'Non-payment suspension may only be triggered by the BILLING_INTERNAL role.',
                403,
            );
        }

        $data = $request->validate([
            'dunningReasonCode' => ['required', 'string', 'max:64'],
            'dunningCycleReference' => ['required', 'string', 'max:128'],
            'outstandingDebtAmount' => ['required', 'numeric'],
            'outstandingDebtCurrency' => ['required', 'string', 'size:3'],
            'dunningEscalationLevel' => ['nullable', 'integer'],
        ]);

        return $this->trigger($request, $subscription, 'SUSPEND_NP', $data);
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

    /** GET /api/subscription-operations/{operation} — framework operation status (§8.3). */
    public function status(SubscriptionOperation $operation): JsonResponse
    {
        return ApiResponse::item($operation);
    }

    /** POST /api/subscription-operations/{operation}/cancel — cancel in-flight (§8.2). */
    public function cancel(Request $request, SubscriptionOperation $operation): JsonResponse
    {
        $data = $request->validate(['cancelReason' => ['required', 'string', 'max:64']]);
        $cancelled = $this->framework->cancel($operation, $data['cancelReason'], $request->user()?->uid);

        return ApiResponse::item($cancelled);
    }

    /** GET /api/subscriptions/{subscription}/in-flight-operation (§8.4). */
    public function inFlight(Subscription $subscription): JsonResponse
    {
        $op = $subscription->operations()->whereNull('final_state')->latest('created_at')->first();
        if (! $op) {
            return response()->json(null, 204);
        }

        return ApiResponse::item($op);
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
