<?php

namespace Modules\Catalog\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Models\PromoCampaign;
use Modules\Catalog\Services\CampaignService;

/** SIP-05 promotional campaign API (MVP: lifecycle + eligibility + redemption). */
class CampaignController extends ApiController
{
    public function __construct(private readonly CampaignService $campaigns) {}

    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = PromoCampaign::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->with('offers', 'channels')
            ->orderByDesc('starts_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'campaign_type' => ['nullable', 'in:ACQUISITION,RETENTION,UPSELL,CROSS_SELL,WINBACK,RECOVERY,LOYALTY,STAFF_PARTNER,REGIONAL'],
            'segment' => ['nullable', 'string', 'max:64'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'budget_limit_amount' => ['nullable', 'numeric', 'min:0'],
            'max_participants' => ['nullable', 'integer', 'min:1'],
            'offers' => ['nullable', 'array'],
            'offers.*.offer_type' => ['required_with:offers', 'in:DISCOUNT,BUNDLE,PACKAGE,MESSAGE_ONLY'],
            'offers.*.discount_code' => ['nullable', 'string'],
            'offers.*.bundle_code' => ['nullable', 'string'],
            'offers.*.assignment_scope_type' => ['nullable', 'string', 'max:32'],
            'target_rules' => ['nullable', 'array'],
            'target_rules.*.rule_type' => ['required_with:target_rules', 'string', 'max:32'],
            'target_rules.*.operator' => ['nullable', 'in:IN,NOT_IN,EQ,GTE,LTE,BETWEEN'],
            'target_rules.*.rule_value_json' => ['required_with:target_rules', 'array'],
            'target_rules.*.hard_exclusion' => ['nullable', 'boolean'],
            'channels' => ['nullable', 'array'],
        ]);
        $data['created_by'] = $request->user()?->uid;

        return ApiResponse::created($this->campaigns->create($data));
    }

    /** POST /api/campaigns/{campaign}/validate — auditable launch checks (R-SIP-CAMP-02/03/04/05). */
    public function validate(PromoCampaign $campaign): JsonResponse
    {
        return ApiResponse::item(['checks' => $this->campaigns->validate($campaign)]);
    }

    public function activate(PromoCampaign $campaign): JsonResponse
    {
        return ApiResponse::item($this->campaigns->activate($campaign));
    }

    public function pause(PromoCampaign $campaign): JsonResponse
    {
        return ApiResponse::item($this->campaigns->pause($campaign));
    }

    public function end(PromoCampaign $campaign): JsonResponse
    {
        return ApiResponse::item($this->campaigns->end($campaign));
    }

    /** POST /api/campaigns/{campaign}/check-eligibility */
    public function checkEligibility(Request $request, PromoCampaign $campaign): JsonResponse
    {
        $context = $request->validate([
            'channelCode' => ['required', 'string', 'max:32'],
            'franchiseId' => ['nullable', 'string'],
            'regionCode' => ['nullable', 'string'],
            'packageRef' => ['nullable', 'string'],
            'customerSegment' => ['nullable', 'string'],
            'customerId' => ['nullable', 'string'],
        ]);

        return ApiResponse::item($this->campaigns->checkEligibility($campaign, $context));
    }

    /** POST /api/campaigns/{campaign}/participate */
    public function participate(Request $request, PromoCampaign $campaign): JsonResponse
    {
        $data = $request->validate([
            'participant_type' => ['required', 'in:LEAD,ORDER,CUSTOMER,ACCOUNT,SUBSCRIPTION'],
            'participant_ref_id' => ['required', 'string'],
            'channelCode' => ['required', 'string', 'max:32'],
            'franchiseId' => ['nullable', 'string'],
            'regionCode' => ['nullable', 'string'],
            'packageRef' => ['nullable', 'string'],
            'customerSegment' => ['nullable', 'string'],
            'customerId' => ['nullable', 'string'],
        ]);
        $data['agentUserId'] = $request->user()?->uid;

        return ApiResponse::created($this->campaigns->participate(
            $campaign, $data['participant_type'], $data['participant_ref_id'], $data,
        ));
    }
}
