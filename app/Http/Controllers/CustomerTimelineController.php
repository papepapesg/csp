<?php

namespace App\Http\Controllers;

use App\Foundation\Events\Outbox\OutboxEvent;
use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CUST-INT-01 Customer Interaction Timeline. A unified, event-sourced chronological
 * view for a customer — aggregates every domain event whose payload references the
 * customer (subscriptions, invoices, payments, tickets, CVM, ...), read from the
 * authoritative outbox log. No per-module coupling.
 */
class CustomerTimelineController extends ApiController
{
    /** GET /api/customers/{customerId}/timeline */
    public function show(Request $request, string $customerId): JsonResponse
    {
        $events = OutboxEvent::query()
            ->where(function ($q) use ($customerId) {
                $q->where('aggregate_id', $customerId)
                    ->orWhereRaw('payload::text LIKE ?', ['%'.$customerId.'%']);
            })
            ->orderByDesc('created_at')
            ->limit((int) $request->query('limit', 100))
            ->get(['event_id', 'event_type', 'topic', 'aggregate_type', 'aggregate_id', 'payload', 'created_at']);

        return ApiResponse::item([
            'customerId' => $customerId,
            'items' => $events->map(fn ($e) => [
                'at' => $e->created_at,
                'eventType' => $e->event_type,
                'topic' => $e->topic,
                'aggregateType' => $e->aggregate_type,
                'aggregateId' => $e->aggregate_id,
                'payload' => $e->payload,
            ]),
        ]);
    }
}
