<?php

namespace Modules\Catalog\Plm\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Plm\Models\Package;
use Modules\Catalog\Plm\Models\PackageLaunchPlan;
use Modules\Catalog\Plm\Services\PackageLaunchService;

/**
 * SIP-02 package launch lifecycle API (DD §6): create launch plan → validate →
 * submit-review (EM-CFG-04) → approval-outcome callback → activate / run-due,
 * retirement, the sellable read model, and per-scope availability suspend/resume.
 */
class PackageLaunchController extends ApiController
{
    public function __construct(private readonly PackageLaunchService $launch) {}

    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = PackageLaunchPlan::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('packageId'), fn ($q, $v) => $q->where('package_id', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('created_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    /** 6.1 POST /api/package-launch-plans */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operatorCode' => ['nullable', 'string'],
            'packageId' => ['required', 'string'],
            'packageCode' => ['nullable', 'string'],
            'packageVersionId' => ['required', 'string'],
            'launchType' => ['required', 'in:FIRST_LAUNCH,VERSION_CUTOVER,REGION_EXPANSION,RESUME'],
            'requestedLaunchAt' => ['nullable', 'date'],
            'effectiveTimezone' => ['nullable', 'string'],
            'requestedByUserId' => ['nullable', 'string'],
            'availability' => ['nullable', 'array'],
            'availability.*.franchiseId' => ['nullable', 'string'],
            'availability.*.techRegionCode' => ['nullable', 'string'],
            'availability.*.channelCode' => ['required_with:availability', 'in:BACKOFFICE,SALES_APP,SELF_CARE,API'],
            'availability.*.availableFrom' => ['nullable', 'date'],
            'availability.*.availableUntil' => ['nullable', 'date'],
        ]);
        $data['requestedByUserId'] ??= $request->user()?->uid;

        return ApiResponse::created($this->launch->createPlan($data));
    }

    public function show(PackageLaunchPlan $launchPlan): JsonResponse
    {
        return ApiResponse::item($launchPlan->load('checks'));
    }

    /** 6.2 POST /api/package-launch-plans/{id}/validate */
    public function validatePlan(PackageLaunchPlan $launchPlan): JsonResponse
    {
        $checks = $this->launch->validatePlan($launchPlan);

        return ApiResponse::item([
            'launchPlanId' => $launchPlan->launch_plan_id,
            'status' => $launchPlan->refresh()->status,
            'checks' => $checks,
        ]);
    }

    /** 6.3 POST /api/package-launch-plans/{id}/submit-review */
    public function submitReview(Request $request, PackageLaunchPlan $launchPlan): JsonResponse
    {
        $data = $request->validate([
            'requesterUserId' => ['nullable', 'string'],
            'comment' => ['nullable', 'string'],
        ]);
        $data['requesterUserId'] ??= $request->user()?->uid;

        return ApiResponse::item($this->launch->submitReview($launchPlan, $data));
    }

    /** 6.4 POST /api/package-launch-plans/{id}/approval-outcome */
    public function approvalOutcome(Request $request, PackageLaunchPlan $launchPlan): JsonResponse
    {
        $data = $request->validate([
            'approvalRequestId' => ['nullable', 'string'],
            'outcome' => ['required', 'in:APPROVED,REJECTED'],
            'decisionComment' => ['nullable', 'string'],
        ]);

        return ApiResponse::item($this->launch->applyApprovalOutcome($launchPlan, $data['outcome'], $request->user()?->uid));
    }

    /** 6.5 POST /api/package-launch-plans/{id}/activate */
    public function activate(PackageLaunchPlan $launchPlan): JsonResponse
    {
        return ApiResponse::item($this->launch->activate($launchPlan));
    }

    /** §11 worker — POST /api/package-launch-plans/run-due */
    public function runDue(Request $request): JsonResponse
    {
        $operator = $request->input('operatorCode', Context::operatorCode());

        return ApiResponse::item(['activated' => $this->launch->activateDuePlans($operator)]);
    }

    /** 6.9 POST /api/package-retirement-plans */
    public function storeRetirement(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operatorCode' => ['nullable', 'string'],
            'packageId' => ['required', 'string'],
            'packageVersionId' => ['nullable', 'string'],
            'retirementType' => ['required', 'in:END_OF_SALE,END_OF_LIFE,RETIRE_VERSION'],
            'effectiveAt' => ['nullable', 'date'],
            'existingSubscriberPolicy' => ['required', 'in:KEEP_AS_IS,MIGRATE_REQUIRED,BLOCK_RENEWAL'],
            'migrationWorkflowRef' => ['nullable', 'string'],
            'reasonCode' => ['nullable', 'string'],
            'createdByUserId' => ['nullable', 'string'],
        ]);
        $data['createdByUserId'] ??= $request->user()?->uid;

        return ApiResponse::created($this->launch->createRetirement($data));
    }

    /** 6.6 GET /api/packages/available */
    public function available(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'operatorCode' => ['nullable', 'string'],
            'channelCode' => ['nullable', 'in:BACKOFFICE,SALES_APP,SELF_CARE,API'],
            'franchiseId' => ['nullable', 'string'],
            'techRegionCode' => ['nullable', 'string'],
            'onDate' => ['nullable', 'date'],
        ]);
        $filters['operatorCode'] ??= Context::operatorCode();

        return ApiResponse::item(['packages' => $this->launch->availablePackages($filters)]);
    }

    /** 6.7 POST /api/packages/{package}/availability/suspend */
    public function suspendAvailability(Request $request, Package $package): JsonResponse
    {
        $scope = $request->validate([
            'packageVersionId' => ['nullable', 'string'],
            'franchiseId' => ['nullable', 'string'],
            'techRegionCode' => ['nullable', 'string'],
            'channelCode' => ['nullable', 'in:BACKOFFICE,SALES_APP,SELF_CARE,API'],
            'reasonCode' => ['nullable', 'string'],
            'comment' => ['nullable', 'string'],
        ]);

        return ApiResponse::item(['suspended' => $this->launch->suspendAvailability($package, $scope)]);
    }

    /** 6.8 POST /api/packages/{package}/availability/resume */
    public function resumeAvailability(Request $request, Package $package): JsonResponse
    {
        $scope = $request->validate([
            'packageVersionId' => ['nullable', 'string'],
            'franchiseId' => ['nullable', 'string'],
            'techRegionCode' => ['nullable', 'string'],
            'channelCode' => ['nullable', 'in:BACKOFFICE,SALES_APP,SELF_CARE,API'],
            'reasonCode' => ['nullable', 'string'],
            'comment' => ['nullable', 'string'],
        ]);

        return ApiResponse::item(['resumed' => $this->launch->resumeAvailability($package, $scope)]);
    }
}
