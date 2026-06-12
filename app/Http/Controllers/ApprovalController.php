<?php

namespace App\Http\Controllers;

use App\Foundation\Approvals\ApprovalDefinition;
use App\Foundation\Approvals\ApprovalRequest;
use App\Foundation\Approvals\ApprovalService;
use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** EM-CFG-04 approval catalog + request lifecycle API. */
class ApprovalController extends ApiController
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function definitions(Request $request): JsonResponse
    {
        return ApiResponse::item(['items' => ApprovalDefinition::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))->get()]);
    }

    public function storeDefinition(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_type' => ['required', 'string'],
            'action' => ['nullable', 'string'],
            'threshold_amount' => ['nullable', 'numeric'],
            'approver_roles' => ['required', 'array'],
            'required_approvals' => ['nullable', 'integer', 'min:1'],
            'allow_requester' => ['nullable', 'boolean'], // APR-6: permit self-approval (default false)
        ]);

        return ApiResponse::created(ApprovalDefinition::query()->create($data + ['definition_id' => Id::make('appd')]));
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::item(['items' => ApprovalRequest::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->orderByDesc('created_at')->limit(100)->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_type' => ['required', 'string'],
            'action' => ['nullable', 'string'],
            'entity_ref' => ['nullable', 'string'],
            'amount' => ['nullable', 'numeric'],
            'payload' => ['nullable', 'array'],
        ]);

        return ApiResponse::created($this->approvals->request($data + ['requested_by' => $request->user()?->uid]));
    }

    public function decide(Request $request, ApprovalRequest $approvalRequest): JsonResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:255']]);

        return ApiResponse::item($this->approvals->decide($approvalRequest, (bool) $data['approve'], $request->user(), $data['reason'] ?? null));
    }
}
