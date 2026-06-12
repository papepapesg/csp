<?php

namespace Modules\Ilm\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ilm\Models\CvmActivity;
use Modules\Ilm\Models\CvmOfferInstance;
use Modules\Ilm\Models\CvmSegmentMembership;
use Modules\Ilm\Models\CvmSignalProfile;
use Modules\Ilm\Services\CvmActivityService;
use Modules\Ilm\Services\CvmEvaluationService;
use Modules\Ilm\Services\CvmOfferService;

/** EM-03 CVM: evaluate customers, manage retention/recovery activities, propose + accept offers. */
class CvmController extends ApiController
{
    public function __construct(
        private readonly CvmEvaluationService $evaluation,
        private readonly CvmActivityService $activities,
        private readonly CvmOfferService $offers,
    ) {}

    /** POST /api/cvm/customers/{customerId}/evaluate — refresh signals + segment (DD §5.1). */
    public function evaluate(Request $request, string $customerId): JsonResponse
    {
        $data = $request->validate([
            'operatorCode' => ['nullable', 'string'], 'reason' => ['nullable', 'string'],
            'sourceEventRef' => ['nullable', 'string'], 'signals' => ['nullable', 'array'],
        ]);
        $result = $this->evaluation->evaluate(
            $data['operatorCode'] ?? Context::operatorCode(), $customerId,
            $data['signals'] ?? [], $data['reason'] ?? null, $data['sourceEventRef'] ?? null,
        );

        return ApiResponse::item($result);
    }

    /** GET /api/cvm/profiles/{customerId} — the customer's signal profile + active segments. */
    public function profile(Request $request, string $customerId): JsonResponse
    {
        $operator = $request->query('operatorCode', Context::operatorCode());

        return ApiResponse::item([
            'profile' => CvmSignalProfile::forCustomer($operator, $customerId),
            'segments' => CvmSegmentMembership::query()->where('operator_code', $operator)->where('customer_id', $customerId)->where('status', 'ACTIVE')->get(),
        ]);
    }

    // ---- activities ----
    public function activities(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = CvmActivity::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('assignedToUserId'), fn ($q, $u) => $q->where('assigned_to_user_id', $u))
            ->orderByDesc('created_at')->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function createActivity(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operatorCode' => ['nullable', 'string'],
            'activityType' => ['required', 'in:RETENTION_CALL,PAYMENT_RECOVERY,UPSELL_OFFER,WINBACK,SERVICE_RECOVERY'],
            'customerId' => ['required', 'string'], 'accountId' => ['nullable', 'string'], 'subscriptionId' => ['nullable', 'string'],
            'sourceEventRef' => ['nullable', 'string'], 'assignedToUserId' => ['nullable', 'string'], 'assignedTeamId' => ['nullable', 'string'],
            'priority' => ['nullable', 'in:LOW,MEDIUM,HIGH,CRITICAL'], 'triggerReason' => ['nullable', 'string'],
        ]);

        return ApiResponse::created($this->activities->create($data));
    }

    public function closeActivity(Request $request, CvmActivity $cvmActivity): JsonResponse
    {
        $data = $request->validate(['outcomeCode' => ['required', 'string'], 'notes' => ['nullable', 'string']]);

        return ApiResponse::item($this->activities->close($cvmActivity, $data['outcomeCode'], $data['notes'] ?? null));
    }

    // ---- offers ----
    public function proposeOffer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operatorCode' => ['nullable', 'string'], 'activityId' => ['nullable', 'string'], 'customerId' => ['required', 'string'],
            'subscriptionId' => ['nullable', 'string'], 'offerType' => ['required', 'in:RETENTION_DISCOUNT,UPGRADE_OFFER,WINBACK_PACKAGE,GOODWILL_CREDIT,PAYMENT_REMINDER'],
            'campaignCode' => ['nullable', 'string'], 'discountRef' => ['nullable', 'string'], 'discountPercent' => ['nullable', 'numeric'],
        ]);
        $data['requestedBy'] = $request->user()?->uid;

        return ApiResponse::created($this->offers->propose($data));
    }

    public function acceptOffer(Request $request, CvmOfferInstance $cvmOffer): JsonResponse
    {
        $data = $request->validate(['acceptedByUserId' => ['nullable', 'string'], 'customerConsentRef' => ['nullable', 'string']]);

        return ApiResponse::item($this->offers->accept($cvmOffer, $data['acceptedByUserId'] ?? $request->user()?->uid, $data['customerConsentRef'] ?? null));
    }

    public function rejectOffer(Request $request, CvmOfferInstance $cvmOffer): JsonResponse
    {
        return ApiResponse::item($this->offers->reject($cvmOffer, $request->input('notes')));
    }
}
