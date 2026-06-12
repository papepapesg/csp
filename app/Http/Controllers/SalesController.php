<?php

namespace App\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use App\Models\SalesLead;
use App\Models\SalesTerritory;
use App\Services\SalesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** SALES-01 commercial pipeline API (FE-APP-02 Outdoor Sales / Franchise App + Backoffice). */
class SalesController extends ApiController
{
    public function __construct(private readonly SalesService $sales) {}

    // ---- leads ----
    public function leads(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = SalesLead::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('agentId'), fn ($q, $a) => $q->where('assigned_agent', $a))
            ->when($request->query('territoryCode'), fn ($q, $t) => $q->where('territory', $t))
            ->orderByDesc('created_at')->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function createLead(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operatorCode' => ['nullable', 'string'],
            'sourceChannel' => ['nullable', 'in:DOOR_TO_DOOR,FRANCHISE_WALKIN,CALLBACK,REFERRAL'],
            'prospectName' => ['nullable', 'string'], 'primaryPhone' => ['required', 'string'],
            'homepassId' => ['nullable', 'string'], 'territoryCode' => ['nullable', 'string'], 'packageRef' => ['nullable', 'string'],
            'geo' => ['nullable', 'array'], 'consentCaptured' => ['nullable', 'boolean'],
            'createdByAgentId' => ['nullable', 'string'], 'franchiseCode' => ['nullable', 'string'],
            'packageInterest' => ['nullable', 'array'],
        ]);

        return ApiResponse::created($this->sales->createLead($data));
    }

    public function showLead(SalesLead $lead): JsonResponse
    {
        return ApiResponse::item([
            'lead' => $lead,
            'assignments' => \App\Models\SalesLeadAssignment::query()->where('lead_id', $lead->lead_id)->orderByDesc('assigned_at')->get(),
            'activities' => \App\Models\SalesActivity::query()->where('lead_id', $lead->lead_id)->orderByDesc('created_at')->get(),
            'conversion' => \App\Models\SalesConversion::query()->where('lead_id', $lead->lead_id)->first(),
        ]);
    }

    public function assign(Request $request, SalesLead $lead): JsonResponse
    {
        $data = $request->validate([
            'assignedAgentId' => ['nullable', 'string'], 'assignedTeamId' => ['nullable', 'string'], 'reasonCode' => ['nullable', 'string'],
        ]);
        $data['assignedByUserId'] = $request->user()?->uid;

        return ApiResponse::item($this->sales->assign($lead, $data));
    }

    public function addActivity(Request $request, SalesLead $lead): JsonResponse
    {
        $data = $request->validate([
            'activityType' => ['required', 'in:VISIT,CALL,SMS,FOLLOW_UP,CONVERSION'],
            'outcomeCode' => ['nullable', 'string'], 'notes' => ['nullable', 'string'], 'nextFollowUpAt' => ['nullable', 'date'], 'agentId' => ['nullable', 'string'],
        ]);

        return ApiResponse::created($this->sales->addActivity($lead, $data));
    }

    public function qualify(SalesLead $lead): JsonResponse
    {
        return ApiResponse::item($this->sales->qualify($lead));
    }

    public function convertToOrder(Request $request, SalesLead $lead): JsonResponse
    {
        $data = $request->validate([
            'selectedPackageId' => ['nullable', 'string'], 'serviceAddress' => ['nullable', 'string'],
            'customerDraft' => ['nullable', 'array'], 'kycFileRefs' => ['nullable', 'array'], 'salesAttribution' => ['nullable', 'array'],
        ]);

        return ApiResponse::created($this->sales->convertToOrder($lead, $data));
    }

    public function lose(Request $request, SalesLead $lead): JsonResponse
    {
        return ApiResponse::item($this->sales->lose($lead, $request->input('reason')));
    }

    public function dailyWork(Request $request, string $agentId): JsonResponse
    {
        return ApiResponse::item(['agentId' => $agentId, 'leads' => $this->sales->dailyWork($request->query('operatorCode', Context::operatorCode()), $agentId)]);
    }

    // ---- territories ----
    public function territories(Request $request): JsonResponse
    {
        $items = SalesTerritory::query()->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('active'), fn ($q) => $q->where('active', true))->orderBy('territory_code')->get();

        return ApiResponse::item(['items' => $items]);
    }

    public function createTerritory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'territory_code' => ['required', 'string'], 'name' => ['required', 'string'],
            'tech_region_code' => ['nullable', 'string'], 'franchise_contractor_id' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'], 'metadata_json' => ['nullable', 'array'],
        ]);

        return ApiResponse::created(SalesTerritory::query()->updateOrCreate(
            ['operator_code' => $request->input('operatorCode', Context::operatorCode()), 'territory_code' => $data['territory_code']], $data,
        ));
    }
}
