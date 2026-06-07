<?php

namespace App\Foundation\Http;

use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;

/**
 * Platform/foundation endpoints: health, version, and the runtime config the
 * frontends load before rendering protected screens (FE-APP-00 §4.1).
 */
class PlatformController extends ApiController
{
    public function health(): JsonResponse
    {
        return ApiResponse::item([
            'status' => 'UP',
            'service' => config('sophix.name'),
            'version' => config('sophix.version'),
            'time' => now()->toIso8601String(),
            'correlationId' => Context::correlationId(),
        ]);
    }

    public function runtimeConfig(): JsonResponse
    {
        return ApiResponse::item([
            'operator' => Context::operatorCode(),
            'country' => config('sophix.default_country'),
            'currency' => config('sophix.default_currency'),
            'timezone' => config('sophix.default_timezone'),
            'locale' => app()->getLocale(),
            'featureFlags' => [
                'walletEnabled' => true,
                'taxInvoiceEnabled' => true,
                'selfPauseEnabled' => false,
            ],
            'drivers' => [
                'eventBus' => config('sophix.event_bus'),
                'workflow' => config('sophix.workflow_driver'),
                'rules' => config('sophix.rules_driver'),
            ],
        ]);
    }
}
