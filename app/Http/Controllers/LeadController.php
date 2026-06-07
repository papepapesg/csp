<?php

namespace App\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use App\Models\SalesLead;
use App\Services\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** SALES-01 lead funnel API. */
class LeadController extends ApiController
{
    public function __construct(private readonly LeadService $leads) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::item(['items' => SalesLead::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('franchiseCode'), fn ($q, $f) => $q->where('franchise_code', $f))
            ->orderByDesc('created_at')->limit(100)->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'msisdn' => ['required', 'string', 'max:24'],
            'source' => ['nullable', 'in:FIELD,CALL,WEB,USSD'],
            'territory' => ['nullable', 'string'],
            'franchise_code' => ['nullable', 'string'],
            'assigned_agent' => ['nullable', 'string'],
            'homepass_id' => ['nullable', 'string'],
            'package_ref' => ['nullable', 'string'],
        ]);

        return ApiResponse::created($this->leads->capture($data));
    }

    public function qualify(SalesLead $lead): JsonResponse
    {
        return ApiResponse::item($this->leads->qualify($lead));
    }

    public function convert(SalesLead $lead): JsonResponse
    {
        return ApiResponse::item($this->leads->convert($lead));
    }

    public function lose(Request $request, SalesLead $lead): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return ApiResponse::item($this->leads->lose($lead, $data['reason'] ?? null));
    }
}
