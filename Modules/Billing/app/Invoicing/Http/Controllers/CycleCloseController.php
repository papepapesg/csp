<?php

namespace Modules\Billing\Invoicing\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** BIL cycle-close run monitor — read-only view of recent billing-cycle close runs. */
class CycleCloseController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $runs = DB::table('cycle_close_run')
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->orderByDesc('started_at')
            ->limit(50)
            ->get();

        return ApiResponse::item(['items' => $runs]);
    }
}
