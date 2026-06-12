<?php

namespace Modules\Workforce\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Workforce\Models\ContractorAvailabilitySlot;
use Modules\Workforce\Models\ContractorSlotCommitment;
use Modules\Workforce\Services\ContractorAvailabilityService;

/** EM-02 §5.1/5.2 contractor availability + slot commitment (the WO module's hot path). */
class ContractorAvailabilityController extends ApiController
{
    public function __construct(private readonly ContractorAvailabilityService $availability) {}

    /** POST /api/contractor-availability — ranked contractors with capacity for region+scope+skills+window. */
    public function availability(Request $request): JsonResponse
    {
        $v = $request->validate([
            'techRegionId' => ['required', 'string'],
            'serviceScope' => ['required', 'string'],
            'requiredSkills' => ['nullable', 'array'],
            'requiredSkills.*' => ['string'],
            'windowStart' => ['required', 'date'],
            'windowEnd' => ['required', 'date'],
            'concurrentDemand' => ['nullable', 'integer', 'min:1'],
            'preferEmergency' => ['nullable', 'boolean'],
        ]);

        return ApiResponse::item(array_merge(
            ['operatorCode' => Context::operatorCode(), 'techRegionId' => $v['techRegionId'], 'evaluatedAt' => now()->toIso8601String()],
            $this->availability->resolve(
                $v['techRegionId'], $v['serviceScope'], $v['requiredSkills'] ?? [],
                Carbon::parse($v['windowStart']), Carbon::parse($v['windowEnd']),
                $v['concurrentDemand'] ?? 1, $v['preferEmergency'] ?? false,
            ),
        ));
    }

    /** POST /api/contractor-slot-commitments — atomically reserve capacity for a WO. */
    public function commit(Request $request): JsonResponse
    {
        $v = $request->validate([
            'slotId' => ['required', 'string'],
            'woId' => ['required', 'string'],
            'committedForDatetime' => ['required', 'date'],
            'qty' => ['nullable', 'integer', 'min:1'],
        ]);

        return ApiResponse::created($this->availability->commit($v['slotId'], $v['woId'], Carbon::parse($v['committedForDatetime']), $v['qty'] ?? 1));
    }

    /** POST /api/contractor-slot-commitments/{commitment}/consume — WO completed. */
    public function consume(ContractorSlotCommitment $commitment): JsonResponse
    {
        return ApiResponse::item($this->availability->consume($commitment));
    }

    /** DELETE /api/contractor-slot-commitments/{commitment} — WO cancelled, capacity released. */
    public function release(ContractorSlotCommitment $commitment): JsonResponse
    {
        return ApiResponse::item($this->availability->release($commitment));
    }
}
