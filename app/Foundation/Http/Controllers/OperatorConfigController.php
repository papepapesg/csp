<?php

namespace App\Foundation\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Models\OperatorConfig;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Operator deployment configuration: read by every frontend, edited by admins. */
class OperatorConfigController extends ApiController
{
    /** GET /api/operator-config — the caller's operator settings. */
    public function show(Request $request): JsonResponse
    {
        $config = OperatorConfig::forOperator($request->query('operatorCode', Context::operatorCode()));

        return ApiResponse::item($config ?? ['operator_code' => Context::operatorCode()]);
    }

    /** PATCH /api/operator-config — admin update (itops.manage). */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:255'],
            'default_locale' => ['sometimes', 'string', 'max:8'],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'date_format' => ['sometimes', 'string', 'max:32'],
            'theme_primary_color' => ['sometimes', 'string', 'max:16'],
            'theme_logo_url' => ['nullable', 'string', 'max:1024'],
            'log_level' => ['sometimes', 'in:debug,info,warning,error'],
            'extras' => ['nullable', 'array'],
        ]);

        $config = OperatorConfig::query()->updateOrCreate(
            ['operator_code' => Context::operatorCode()],
            $data + ['updated_by' => $request->user()?->uid, 'display_name' => $data['display_name'] ?? Context::operatorCode()],
        );

        return ApiResponse::item($config);
    }
}
