<?php

namespace Modules\Ticketing\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ticketing\Models\SlaPolicy;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Services\TicketService;

/**
 * TCK-01 Ticketing & Case Management API.
 */
class TicketController extends ApiController
{
    public function __construct(private readonly TicketService $tickets) {}

    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = Ticket::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('customerId'), fn ($q, $c) => $q->where('customer_id', $c))
            ->when($request->query('queue'), fn ($q, $g) => $q->where('queue', $g))
            ->when($request->query('assigneeId'), fn ($q, $a) => $q->where('assignee_id', $a))
            ->orderByDesc('created_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:64'], // governed by the ticket_category_catalog, not a code enum
            'subcategory' => ['nullable', 'string', 'max:64'],
            'priority' => ['nullable', 'in:LOW,NORMAL,HIGH,URGENT'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'customer_id' => ['nullable', 'string'],
            'account_id' => ['nullable', 'string'],
            'subscription_id' => ['nullable', 'string'],
            'queue' => ['nullable', 'string', 'max:64'],
            // TCK-2 / §8.1: inline entity links satisfy the "must link to an entity" rule.
            'links' => ['nullable', 'array'],
            'links.*.entity_type' => ['required_with:links', 'string'],
            'links.*.entity_ref' => ['required_with:links', 'string'],
            'links.*.relation' => ['nullable', 'string'],
        ]);
        $data['opened_by'] = $request->user()?->uid;

        return ApiResponse::created($this->tickets->create($data));
    }

    public function show(Ticket $ticket): JsonResponse
    {
        return ApiResponse::item($ticket->load(['comments', 'timeline']));
    }

    public function assign(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate(['assignee_id' => ['required', 'string']]);

        return ApiResponse::item($this->tickets->assign($ticket, $data['assignee_id'], $request->user()?->uid));
    }

    public function addAttachment(Request $request, Ticket $ticket): JsonResponse
    {
        $v = $request->validate([
            'file_id' => ['required', 'string'],
            'file_name' => ['required', 'string'],
            'content_type' => ['nullable', 'string'],
            'size_bytes' => ['nullable', 'integer'],
        ]);

        return ApiResponse::item($this->tickets->addAttachment($ticket, $v, $request->user()?->uid), 201);
    }

    public function link(Request $request, Ticket $ticket): JsonResponse
    {
        $v = $request->validate([
            'entity_type' => ['required', 'string'],
            'entity_ref' => ['required', 'string'],
            'relation' => ['nullable', 'string'],
        ]);

        return ApiResponse::item($this->tickets->linkEntity($ticket, $v['entity_type'], $v['entity_ref'], $v['relation'] ?? 'RELATED', $request->user()?->uid), 201);
    }

    public function comment(Request $request, Ticket $ticket): JsonResponse
    {
        $v = $request->validate([
            'body' => ['required', 'string'],
            'visibility' => ['nullable', 'in:INTERNAL,CUSTOMER_VISIBLE'],
        ]);

        return ApiResponse::created($this->tickets->comment($ticket, [
            'body' => $v['body'],
            'visibility' => $v['visibility'] ?? 'INTERNAL',
            'author_id' => $request->user()?->uid,
        ]));
    }

    public function reopen(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate([
            'reason_code' => ['required', 'string', 'max:64'],
            'comment' => ['nullable', 'string'],
        ]);

        return ApiResponse::item($this->tickets->reopen($ticket, $data['reason_code'], $data['comment'] ?? null, $request->user()?->uid));
    }

    public function cancel(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return ApiResponse::item($this->tickets->cancel($ticket, $data['reason'] ?? null, $request->user()?->uid));
    }

    public function createWorkOrder(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate([
            'priority' => ['nullable', 'in:LOW,NORMAL,HIGH,URGENT'],
            'tech_region_id' => ['nullable', 'string'],
            'homepass_id' => ['nullable', 'string'],
        ]);

        return ApiResponse::item($this->tickets->createWorkOrder($ticket, $data, $request->user()?->uid));
    }

    public function resolve(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate([
            'resolution_code' => ['nullable', 'string', 'max:64'],
            'resolution_note' => ['nullable', 'string'],
        ]);

        return ApiResponse::item($this->tickets->resolve($ticket, $data, $request->user()?->uid));
    }

    public function close(Request $request, Ticket $ticket): JsonResponse
    {
        return ApiResponse::item($this->tickets->close($ticket, $request->user()?->uid));
    }

    /** GET /api/sla-policies — the SLA catalog. */
    public function slaPolicies(): JsonResponse
    {
        return ApiResponse::item(['items' => SlaPolicy::query()->orderBy('priority')->get()]);
    }

    /** POST /api/sla-policies — set/override an SLA (operator/category/priority -> hours). */
    public function storeSlaPolicy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operator_code' => ['nullable', 'string', 'max:16'],
            'category' => ['nullable', 'string', 'max:32'],
            'priority' => ['required', 'in:LOW,NORMAL,HIGH,URGENT'],
            'response_hours' => ['required', 'integer', 'min:1'],
        ]);
        $policy = SlaPolicy::query()->updateOrCreate(
            ['operator_code' => $data['operator_code'] ?? null, 'category' => $data['category'] ?? null, 'priority' => $data['priority']],
            ['response_hours' => $data['response_hours']],
        );

        return ApiResponse::created($policy);
    }
}
