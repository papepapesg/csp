<?php

namespace App\Http\Controllers;

use App\Foundation\Approvals\ApprovalDefinition;
use App\Foundation\Approvals\ApprovalRequest;
use App\Foundation\Approvals\ApprovalService;
use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** EM-CFG-04 approval catalog + request lifecycle API. */
class ApprovalController extends ApiController
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function definitions(Request $request): JsonResponse
    {
        return ApiResponse::item(['items' => ApprovalDefinition::query()
            ->with('stages')
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))->get()]);
    }

    public function storeDefinition(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_type' => ['required', 'string'],
            'action' => ['nullable', 'string'],
            'threshold_amount' => ['nullable', 'numeric'],
            'active' => ['nullable', 'boolean'],
            // The approval chain: ≥1 stage, each targeting a ROLE pool or a named USER, in sequence.
            'stages' => ['required', 'array', 'min:1'],
            'stages.*.name' => ['nullable', 'string'],
            'stages.*.approver_kind' => ['required', 'in:ROLE,USER'],
            'stages.*.approver_roles' => ['nullable', 'array'],
            'stages.*.approver_user_ref' => ['nullable', 'string'],
            'stages.*.approver_email' => ['nullable', 'email'],
            'stages.*.required_approvals' => ['nullable', 'integer', 'min:1'],
            'stages.*.allow_requester' => ['nullable', 'boolean'],
        ]);

        $def = ApprovalDefinition::defineChain(
            Context::operatorCode(),
            $data['entity_type'],
            $data['action'] ?? null,
            $data['stages'],
            ['threshold_amount' => $data['threshold_amount'] ?? null, 'active' => $data['active'] ?? true],
        );

        return ApiResponse::created($def->load('stages'));
    }

    /**
     * EM-CFG-04: provision a lightweight login for a named approver (e.g. a "director" who holds no
     * platform role). The created user can authenticate and act on USER-kind stages that name them
     * by email/uid. Idempotent on email within the operator.
     */
    public function inviteApprover(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'name' => ['required', 'string'],
        ]);
        $operator = Context::operatorCode();

        $user = User::query()->firstOrCreate(
            ['email' => $data['email'], 'operator_code' => $operator],
            ['name' => $data['name'], 'status' => 'INVITED', 'password' => Hash::make(Str::random(40))],
        );

        return ApiResponse::created(['uid' => $user->uid, 'email' => $user->email, 'name' => $user->name, 'status' => $user->status]);
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
