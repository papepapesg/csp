<?php

namespace App\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use App\Services\UssdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** FE-CH-USSD-01 USSD gateway adapter — config-driven menus, returns CON/END text. */
class UssdController extends ApiController
{
    public function __construct(private readonly UssdService $ussd) {}

    /** POST /api/ussd — Africa's-Talking-style webhook (accumulated text). */
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'sessionId' => ['required', 'string'],
            'phoneNumber' => ['required', 'string'],
            'text' => ['nullable', 'string'],
        ]);
        $result = $this->ussd->handle($data['sessionId'], $data['phoneNumber'], $data['text'] ?? '');

        return new Response($result['message'], 200, ['Content-Type' => 'text/plain']);
    }

    /**
     * POST /api/channels/ussd/sessions — the DD's normalized, aggregator-agnostic endpoint
     * (FE-CH-USSD-01 §6). Returns a JSON envelope with the CON/END text.
     */
    public function session(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operatorCode' => ['nullable', 'string'],
            'gatewaySessionId' => ['required', 'string'],
            'gatewayRequestId' => ['nullable', 'string'],
            'msisdn' => ['required', 'string'],
            'inputText' => ['nullable', 'string'],
            'shortCode' => ['nullable', 'string'],
        ]);
        if (! empty($data['operatorCode'])) {
            Context::setOperatorCode($data['operatorCode']);
        }
        $result = $this->ussd->handle($data['gatewaySessionId'], $data['msisdn'], $data['inputText'] ?? '', $data['gatewayRequestId'] ?? null);

        return ApiResponse::item([
            'responseType' => $result['continue'] ? 'CON' : 'END',
            'text' => substr($result['message'], 4),
            'continue' => $result['continue'],
        ]);
    }
}
