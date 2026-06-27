<?php

namespace Modules\Billing\Mediation\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Mediation\Models\RatedEvent;
use Modules\Billing\Mediation\Models\UsageRecord;
use Modules\Billing\Mediation\Services\MediationRatingService;

/** MED-01 mediation + RAT-01 rating API. */
class UsageController extends ApiController
{
    public function __construct(private readonly MediationRatingService $service) {}

    /** POST /api/usage (batch ingest) */
    public function ingest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'records' => ['required', 'array', 'min:1'],
            'records.*.usage_type' => ['required', 'in:VOICE,DATA,SMS'],
            'records.*.quantity' => ['required', 'numeric', 'min:0'],
            'records.*.source_ref' => ['required', 'string'],
            'records.*.destination' => ['nullable', 'string'],
            'records.*.subscription_id' => ['nullable', 'string'],
        ]);

        return ApiResponse::item($this->service->ingest($data['records']));
    }

    /** POST /api/usage/rate-run */
    public function rateRun(Request $request): JsonResponse
    {
        return ApiResponse::item($this->service->ratePending($request->input('operatorCode', Context::operatorCode())));
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::item(['items' => UsageRecord::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->orderByDesc('created_at')->limit(100)->get()]);
    }

    /**
     * GET /api/rated-events?invoice_id=… — RAT-01 rated usage audit. With
     * invoice_id this IS the invoice's itemized usage page: every rated event the
     * invoice consumed, each with its CDR detail (destination, occurred_at,
     * quantity/duration) from the mediated usage record.
     */
    public function ratedEvents(Request $request): JsonResponse
    {
        $events = RatedEvent::query()->with('usage')
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('invoice_id'), fn ($q, $i) => $q->where('invoice_id', $i))
            ->when($request->query('subscription_id'), fn ($q, $s) => $q->where('subscription_id', $s))
            ->orderByDesc('created_at')->limit(1000)->get();

        return ApiResponse::item(['items' => $events->map(fn (RatedEvent $e) => [
            'rated_id' => $e->rated_id,
            'invoice_id' => $e->invoice_id,
            'subscription_id' => $e->subscription_id,
            'tariff_code' => $e->tariff_code,
            'rate' => $e->rate,
            'amount' => $e->amount,
            'usage_type' => $e->usage?->usage_type,
            'destination' => $e->usage?->destination,
            'occurred_at' => $e->usage?->occurred_at,
            'quantity' => $e->usage?->quantity, // seconds for voice, MB for data, count for SMS
        ])]);
    }
}
