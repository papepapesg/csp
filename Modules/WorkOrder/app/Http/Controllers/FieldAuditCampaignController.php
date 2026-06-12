<?php

namespace Modules\WorkOrder\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\WorkOrder\Models\FieldAuditDiscrepancy;
use Modules\WorkOrder\Models\FieldAuditTask;
use Modules\WorkOrder\Services\FieldAuditCampaignService;

/** FA-01/02/03 campaign/task/observation/discrepancy API (the unified field-audit capability). */
class FieldAuditCampaignController extends ApiController
{
    public function __construct(private readonly FieldAuditCampaignService $audits) {}

    public function createCampaign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operatorCode' => ['nullable', 'string'],
            'auditType' => ['required', 'in:EQUIPMENT,NETWORK,KYC'],
            'campaignType' => ['required', 'string'],
            'areaCode' => ['nullable', 'string'], 'technologyFamily' => ['nullable', 'string'],
            'scheduledStartAt' => ['nullable', 'date'], 'scheduledEndAt' => ['nullable', 'date'],
        ]);
        $data['createdByUserId'] = $request->user()?->uid;

        return ApiResponse::created($this->audits->createCampaign($data));
    }

    public function createTask(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operatorCode' => ['nullable', 'string'], 'campaignId' => ['nullable', 'string'],
            'auditType' => ['required', 'in:EQUIPMENT,NETWORK,KYC'],
            'taskType' => ['nullable', 'in:CUSTOMER_PREMISES,FIELD_SITE,POST_SWAP,INVESTIGATION'],
            'customerId' => ['nullable', 'string'], 'accountId' => ['nullable', 'string'], 'subscriptionId' => ['nullable', 'string'],
            'homepassId' => ['nullable', 'string'], 'assignedToUserId' => ['nullable', 'string'], 'assignedTeamId' => ['nullable', 'string'],
            'dueAt' => ['nullable', 'date'], 'sourceEventRef' => ['nullable', 'string'], 'createWorkOrder' => ['nullable', 'boolean'],
            'expectedItems' => ['nullable', 'array'],
        ]);

        return ApiResponse::created($this->audits->createTask($data));
    }

    public function tasks(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = FieldAuditTask::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('assignedToUserId'), fn ($q, $u) => $q->where('assigned_to_user_id', $u))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function showTask(FieldAuditTask $fieldAuditTask): JsonResponse
    {
        return ApiResponse::item([
            'task' => $fieldAuditTask,
            'expectedItems' => $fieldAuditTask->expectedItems()->get(),
            'observations' => $fieldAuditTask->observations()->get(),
            'discrepancies' => $fieldAuditTask->discrepancies()->get(),
        ]);
    }

    public function submitObservation(Request $request, FieldAuditTask $fieldAuditTask): JsonResponse
    {
        $data = $request->validate([
            'expectedItemId' => ['nullable', 'string'],
            'observedEquipmentInstanceId' => ['nullable', 'string'], 'observedSkuId' => ['nullable', 'string'],
            'observedSerialNumber' => ['nullable', 'string'],
            'presenceStatus' => ['nullable', 'in:PRESENT,MISSING,FOUND_EXTRA,NOT_ACCESSIBLE'],
            'conditionStatus' => ['nullable', 'in:WORKING,DAMAGED,TAMPERED,UNKNOWN'],
            'observedLocationRef' => ['nullable', 'string'],
            'photoFileIds' => ['nullable', 'array'], 'gpsLatitude' => ['nullable', 'numeric'], 'gpsLongitude' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string'], 'offlineClientRef' => ['nullable', 'string'],
        ]);
        $data['capturedByUserId'] = $request->user()?->uid;

        return ApiResponse::created($this->audits->submitObservation($fieldAuditTask, $data));
    }

    public function discrepancies(Request $request): JsonResponse
    {
        $items = FieldAuditDiscrepancy::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('auditTaskId'), fn ($q, $t) => $q->where('audit_task_id', $t))
            ->orderByDesc('created_at')->get();

        return ApiResponse::item(['items' => $items]);
    }

    public function approvalOutcome(Request $request, FieldAuditDiscrepancy $fieldAuditDiscrepancy): JsonResponse
    {
        $data = $request->validate(['outcome' => ['required', 'in:APPROVED,REJECTED']]);

        return ApiResponse::item($this->audits->applyApprovalOutcome($fieldAuditDiscrepancy, $data['outcome']));
    }

    public function resolveDiscrepancy(Request $request, FieldAuditDiscrepancy $fieldAuditDiscrepancy): JsonResponse
    {
        return ApiResponse::item($this->audits->resolveDiscrepancy($fieldAuditDiscrepancy, $request->input('note')));
    }
}
