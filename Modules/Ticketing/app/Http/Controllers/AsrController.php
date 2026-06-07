<?php

namespace Modules\Ticketing\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ticketing\Services\AsrService;

/** ASR-01..04 intake API (creates a routed, ASR-typed ticket). */
class AsrController extends ApiController
{
    public function __construct(private readonly AsrService $asr) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asr_type' => ['required', 'in:TECHNICAL_TROUBLE,INFORMATION_REQUEST,COMPLAINT,SERVICE_REQUEST'],
            'subject' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'customer_id' => ['nullable', 'string'],
            'account_id' => ['nullable', 'string'],
            'subscription_id' => ['nullable', 'string'],
            'priority' => ['nullable', 'in:LOW,NORMAL,HIGH,URGENT'],
        ]);

        return ApiResponse::created($this->asr->create($data + ['opened_by' => $request->user()?->uid]));
    }
}
