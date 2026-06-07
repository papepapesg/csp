<?php

namespace Modules\Billing\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Models\RatedEvent;
use Modules\Billing\Models\UsageRecord;
use Modules\Billing\Services\MediationRatingService;

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

    public function ratedEvents(Request $request): JsonResponse
    {
        return ApiResponse::item(['items' => RatedEvent::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->orderByDesc('created_at')->limit(100)->get()]);
    }
}
