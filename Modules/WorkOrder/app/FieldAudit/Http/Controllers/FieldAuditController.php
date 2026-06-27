<?php

namespace Modules\WorkOrder\FieldAudit\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\WorkOrder\FieldAudit\Models\FieldAudit;
use Modules\WorkOrder\FieldAudit\Services\FieldAuditService;

/** FA-01/02/03 field-audit API. */
class FieldAuditController extends ApiController
{
    public function __construct(private readonly FieldAuditService $audits) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::item(['items' => FieldAudit::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->orderByDesc('created_at')->limit(100)->get()]);
    }

    public function show(FieldAudit $fieldAudit): JsonResponse
    {
        return ApiResponse::item($fieldAudit);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:EQUIPMENT,NETWORK,KYC'],
            'target_type' => ['nullable', 'string'],
            'target_ref' => ['nullable', 'string'],
            'assigned_to' => ['nullable', 'string'],
            'scheduled_at' => ['nullable', 'date'],
        ]);

        return ApiResponse::created($this->audits->schedule($data));
    }

    /** POST /api/field-audits/{audit}/findings */
    public function submitFindings(Request $request, FieldAudit $fieldAudit): JsonResponse
    {
        $data = $request->validate([
            'findings' => ['required', 'array'],
            'photo_file_ids' => ['nullable', 'array'],
        ]);

        return ApiResponse::item($this->audits->submitFindings(
            $fieldAudit, $data['findings'], $data['photo_file_ids'] ?? [], $request->user()?->uid
        ));
    }
}
