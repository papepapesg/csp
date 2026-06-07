<?php

namespace Modules\Ilm\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ilm\Models\CvmActivity;
use Modules\Ilm\Services\CvmService;

/** EM-03 CVM activity API. */
class CvmController extends ApiController
{
    public function __construct(private readonly CvmService $cvm) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::item(['items' => CvmActivity::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('customerId'), fn ($q, $c) => $q->where('customer_id', $c))
            ->orderByDesc('created_at')->limit(100)->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'string'],
            'subscription_id' => ['nullable', 'string'],
            'type' => ['required', 'in:RETENTION,RECOVERY,WINBACK'],
            'trigger_reason' => ['nullable', 'string'],
            'segment' => ['nullable', 'string'],
            'channel' => ['nullable', 'string'],
        ]);

        return ApiResponse::created($this->cvm->createActivity($data));
    }

    public function decide(Request $request, CvmActivity $cvmActivity): JsonResponse
    {
        $data = $request->validate(['accept' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:255']]);

        return ApiResponse::item($this->cvm->decide($cvmActivity, (bool) $data['accept'], $data['reason'] ?? null));
    }
}
