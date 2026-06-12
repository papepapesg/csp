<?php

namespace App\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use App\Services\BackofficeDashboardService;
use Illuminate\Http\JsonResponse;

/** FE-APP-01 §7.1 home dashboard summary (role-aware operational widgets, resilient per-tile). */
class DashboardController extends ApiController
{
    public function __construct(private readonly BackofficeDashboardService $dashboard) {}

    public function summary(): JsonResponse
    {
        return ApiResponse::item($this->dashboard->summary(Context::operatorCode()));
    }
}
