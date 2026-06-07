<?php

namespace Modules\Ticketing\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'category' => ['required', 'in:TECHNICAL,BILLING,INFORMATION,COMPLAINT,SERVICE_REQUEST'],
            'subcategory' => ['nullable', 'string', 'max:64'],
            'priority' => ['nullable', 'in:LOW,NORMAL,HIGH,URGENT'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'customer_id' => ['nullable', 'string'],
            'account_id' => ['nullable', 'string'],
            'subscription_id' => ['nullable', 'string'],
            'queue' => ['nullable', 'string', 'max:64'],
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

    public function comment(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string'],
            'internal' => ['sometimes', 'boolean'],
        ]);
        $data['author_id'] = $request->user()?->uid;

        return ApiResponse::created($this->tickets->comment($ticket, $data));
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
}
