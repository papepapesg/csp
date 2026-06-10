<?php

namespace Modules\Billing\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Models\DunningState;
use Modules\Billing\Services\DunningService;

/** BIL-04 dunning API. */
class DunningController extends ApiController
{
    public function __construct(private readonly DunningService $dunning) {}

    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = DunningState::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('minLevel'), fn ($q, $l) => $q->where('current_level', '>=', (int) $l))
            ->orderByDesc('current_level')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    /** POST /api/dunning/run — trigger a scan now. */
    public function run(): JsonResponse
    {
        return ApiResponse::item($this->dunning->scan());
    }

    /** POST /api/dunning/{account}/clear — settle/clear dunning for an account. */
    public function clear(string $account): JsonResponse
    {
        $this->dunning->clear($account);

        return ApiResponse::item(['accountId' => $account, 'status' => 'CLEARED']);
    }

    /** R-5 admin overrides (DUNNING_ADMIN): clear-without-payment, hold, confirm/extend review. */
    public function adminClear(Request $request, string $account): JsonResponse
    {
        $this->dunning->adminClear($account, $request->user()?->uid);

        return ApiResponse::item(['accountId' => $account, 'status' => 'CLEARED']);
    }

    public function hold(Request $request, string $account): JsonResponse
    {
        $this->dunning->hold($account, $request->user()?->uid);

        return ApiResponse::item(['accountId' => $account, 'held' => true]);
    }

    public function confirmTermination(Request $request, string $account): JsonResponse
    {
        $this->dunning->confirmTermination($account, $request->user()?->uid);

        return ApiResponse::item(['accountId' => $account, 'status' => 'TERMINATING']);
    }

    public function extendReview(Request $request, string $account): JsonResponse
    {
        $hours = (int) $request->input('hours', 72);
        $this->dunning->extendReview($account, $hours);

        return ApiResponse::item(['accountId' => $account, 'reviewExtendedHours' => $hours]);
    }
}
