<?php

namespace App\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Services\UssdService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** USSD gateway webhook (Africa's-Talking-style) — returns CON/END text. */
class UssdController extends ApiController
{
    public function __construct(private readonly UssdService $ussd) {}

    /** POST /api/ussd */
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
}
