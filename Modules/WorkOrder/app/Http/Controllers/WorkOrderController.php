<?php

namespace Modules\WorkOrder\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\WorkOrder\Models\WorkOrder;
use Modules\WorkOrder\Services\ShiftingFlowService;
use Modules\WorkOrder\Services\SupportFlowService;
use Modules\WorkOrder\Services\WorkOrderService;

/**
 * WO-01 Work Order API. Create/assign by dispatchers (workorder.assign);
 * start/finalize by field technicians (workorder.execute).
 */
class WorkOrderController extends ApiController
{
    public function __construct(
        private readonly WorkOrderService $service,
        private readonly SupportFlowService $supportFlow,
        private readonly ShiftingFlowService $shiftingFlow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = WorkOrder::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('accountId'), fn ($q, $a) => $q->where('account_id', $a))
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->query('techRegionId'), fn ($q, $r) => $q->where('tech_region_id', $r))
            ->when($request->query('assignedTechnicianId'), fn ($q, $t) => $q->where('assigned_technician_id', $t))
            ->when($request->query('contractorId'), fn ($q, $c) => $q->where('contractor_id', $c))
            ->orderByDesc('created_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    /**
     * GET /api/poc/work-orders — POC ONLY (no auth): the seeded WO list, enriched with customer
     * name + a "Contractor › Team › Tech" assignee label + computed SLA, for the YAS Dispatcher
     * Console prototype. Reads the same work_order table the real index() uses.
     */
    public function pocIndex(Request $request): JsonResponse
    {
        $op = $request->query('operatorCode', config('sophix.default_operator', 'WIK'));
        $rows = DB::table('work_order')->where('operator_code', $op)->orderBy('scheduled_at')->limit(50)->get();
        $custs = DB::table('customer')->where('operator_code', $op)->pluck('name', 'customer_id');
        $ctr = DB::table('contractor')->where('operator_code', $op)->pluck('name', 'contractor_id');
        $team = DB::table('contractor_team')->where('operator_code', $op)->pluck('name', 'team_id');
        $staff = DB::table('staff_member')->where('operator_code', $op)->pluck('name', 'staff_id');

        $items = $rows->map(function ($w) use ($custs, $ctr, $team, $staff) {
            $tech = $staff[$w->assigned_technician_id] ?? null;
            $techLabel = $tech ? preg_replace('/^(\S)\S*\s+(\S+)$/', '$1. $2', $tech) : null;
            $assignee = collect([$ctr[$w->contractor_id] ?? null, $team[$w->team_id] ?? null, $techLabel])
                ->filter()->implode(' › ');
            [$sla, $tone] = $this->pocSla($w->sla_due_at);

            return [
                'woNumber' => $w->work_order_id, 'type' => $w->type, 'jobType' => $w->job_type_code,
                'customer' => $custs[$w->customer_id] ?? $w->customer_id, 'techRegion' => $w->tech_region_id,
                'assignee' => $assignee ?: '—', 'priority' => $w->priority, 'status' => $w->status,
                'sla' => $sla, 'slaTone' => $tone, 'scheduled' => $this->pocSched($w->scheduled_at),
            ];
        });

        return ApiResponse::item(['items' => $items->values(), 'total' => 661]);
    }

    /** @return array{0:string,1:string} [display, tone] computed from the SLA due time. */
    private function pocSla(?string $due): array
    {
        if (! $due) {
            return ['—', 'none'];
        }
        $mins = intdiv(Carbon::parse($due)->getTimestamp() - now()->getTimestamp(), 60);
        if ($mins < 0) {
            $a = -$mins;

            return [$a < 5 ? 'Breached 0h' : '-'.intdiv($a, 60).'h '.($a % 60).'m', 'red'];
        }

        return [intdiv($mins, 60).'h '.($mins % 60).'m', $mins < 60 ? 'red' : ($mins < 120 ? 'amber' : 'gray')];
    }

    private function pocSched(?string $at): string
    {
        if (! $at) {
            return '—';
        }
        $d = Carbon::parse($at);
        $day = $d->isToday() ? 'Today' : ($d->isTomorrow() ? 'Tomorrow' : ($d->isYesterday() ? 'Yesterday' : $d->format('d M')));

        return $day.' '.$d->format('H:i');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:INSTALLATION,SUPPORT,SHIFTING,RELOCATION,EQUIPMENT,NOC'],
            'kind' => ['nullable', 'in:SUPPORT,SHIFTING,INSTALLATION'],
            'job_type_code' => ['nullable', 'string', 'max:32'],
            'priority' => ['nullable', 'in:LOW,NORMAL,HIGH,URGENT'],
            'account_id' => ['nullable', 'string'],
            'subscription_id' => ['nullable', 'string'],
            'customer_id' => ['nullable', 'string'],
            'homepass_id' => ['nullable', 'string'],
            'tech_region_id' => ['nullable', 'string'],
            'source_type' => ['nullable', 'in:TICKET,SUBSCRIPTION_OP,FULFILLMENT,MANUAL'],
            'source_ref' => ['nullable', 'string'],
            'originating_context_type' => ['nullable', 'string', 'max:32'],
            'initial_reason' => ['nullable', 'string', 'max:500'],
            'scheduled_at' => ['nullable', 'date'],
        ]);
        $data['created_by'] = $request->user()?->uid;

        return ApiResponse::created($this->service->create($data));
    }

    public function show(WorkOrder $workOrder): JsonResponse
    {
        return ApiResponse::item($workOrder->load('statusHistory'));
    }

    public function assign(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $data = $request->validate([
            'contractor_id' => ['nullable', 'string'],
            'team_id' => ['nullable', 'string'],
            'assigned_technician_id' => ['nullable', 'string'],
        ]);

        return ApiResponse::item($this->service->assign($workOrder, $data, $request->user()?->uid));
    }

    /**
     * POST /work-orders/{wo}/auto-assign — WO-01 dispatch: contractor-with-availability first
     * (EM-02), in-house staff fallback. Returns the assigned WO, or 409 when no one is available
     * (the WO stays PENDING for manual routing).
     */
    public function autoAssign(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $assigned = $this->service->autoAssign($workOrder, $request->user()?->uid);
        if (! $assigned) {
            return ApiResponse::error(
                \App\Foundation\Errors\ErrorCode::CONFLICT,
                'No contractor or technician is available for this work order; it remains PENDING for manual routing.',
                409,
            );
        }

        return ApiResponse::item($assigned);
    }

    public function start(Request $request, WorkOrder $workOrder): JsonResponse
    {
        return ApiResponse::item($this->service->start($workOrder, $request->user()?->uid));
    }

    public function finalize(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $data = $request->validate([
            'resolution_code' => ['nullable', 'string', 'max:64'],
            'findings' => ['nullable', 'array'],
        ]);

        return ApiResponse::item($this->service->finalize($workOrder, $data, $request->user()?->uid));
    }

    public function cancel(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return ApiResponse::item($this->service->cancel($workOrder, $data['reason'] ?? null, $request->user()?->uid));
    }

    /** POST /work-orders/{wo}/reassign — WO-01 §1.7 change contractor/team/tech (status unchanged). */
    public function reassign(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $data = $request->validate([
            'contractor_id' => ['nullable', 'string'],
            'team_id' => ['nullable', 'string'],
            'assigned_technician_id' => ['nullable', 'string'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        return ApiResponse::item($this->service->reassign($workOrder, $data, $data['reason'] ?? null, $request->user()?->uid));
    }

    /** POST /work-orders/{wo}/attachments — WO-01 §1.4 attach a categorised file. */
    public function addAttachment(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:64'],
            'file_uri' => ['required', 'string', 'max:1024'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        return ApiResponse::created($this->service->addAttachment(
            $workOrder, $data['category'], $data['file_uri'], $data['description'] ?? null, $request->user()?->uid,
        ));
    }

    /** POST /work-orders/{wo}/notes — WO-01 §1.3 append a structured note. */
    public function addNote(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $data = $request->validate([
            'note_kind' => ['required', 'string', 'max:64'],
            'payload' => ['nullable', 'array'],
            'body' => ['nullable', 'string'],
        ]);

        return ApiResponse::created($this->service->addNote(
            $workOrder, $data['note_kind'], $data['payload'] ?? [], $data['body'] ?? null, $request->user()?->uid,
        ));
    }

    /** POST /work-orders/{wo}/finalize-first-confirm — WO-01 §3 save evidence, park in FINALIZATION_PENDING. */
    public function finalizeFirstConfirm(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $data = $request->validate([
            'final_reason' => ['nullable', 'string', 'max:64'],
            'resolution_code' => ['nullable', 'string', 'max:64'],
            'findings' => ['nullable', 'array'],
        ]);

        return ApiResponse::item($this->service->finalizeFirstConfirm($workOrder, $data, $request->user()?->uid));
    }

    /** POST /work-orders/{wo}/finalize-second-confirm — WO-01 §4.4 run checklist, COMPLETE. */
    public function finalizeSecondConfirm(Request $request, WorkOrder $workOrder): JsonResponse
    {
        return ApiResponse::item($this->service->finalizeSecondConfirm($workOrder, $request->user()?->uid));
    }

    /** POST /work-orders/{wo}/support-flow — start the WO-01-FLOW-SUPPORT process. */
    public function startSupportFlow(WorkOrder $workOrder): JsonResponse
    {
        $instance = $this->supportFlow->start($workOrder);

        return ApiResponse::accepted(
            entityId: $workOrder->work_order_id,
            extra: ['processInstanceId' => $instance->instance_id, 'processKey' => $instance->process_key],
        );
    }

    /** POST /work-orders/{wo}/resolve — field/desk agent supplies the resolution. */
    public function resolve(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $data = $request->validate([
            'final_reason' => ['required', 'string', 'max:64'],
            'bindings' => ['nullable', 'array'],
        ]);
        $this->supportFlow->resolve($workOrder, $data['final_reason'], $data['bindings'] ?? []);

        return ApiResponse::accepted(entityId: $workOrder->work_order_id, nextAction: 'TRACK_OPERATION');
    }

    /** POST /work-orders/{wo}/shifting-flow — start the WO-01-FLOW-SHIFTING process. */
    public function startShiftingFlow(WorkOrder $workOrder): JsonResponse
    {
        $instance = $this->shiftingFlow->start($workOrder);

        return ApiResponse::accepted(
            entityId: $workOrder->work_order_id,
            extra: ['processInstanceId' => $instance->instance_id, 'processKey' => $instance->process_key],
        );
    }

    /** POST /work-orders/{wo}/advance-phase — field tech completes the current phase. */
    public function advancePhase(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:255']]);
        $this->shiftingFlow->advance($workOrder, $data);

        return ApiResponse::accepted(entityId: $workOrder->work_order_id, nextAction: 'TRACK_OPERATION');
    }
}
