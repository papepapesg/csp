<?php

namespace Modules\Billing\Dunning\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Dunning\Models\DunningState;
use Modules\Billing\Dunning\Services\DunningService;

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

    /** GET /api/dunning/{account} — one dunning state with its pinned program. */
    public function show(string $account): JsonResponse
    {
        $state = DunningState::query()->where('account_id', $account)->firstOrFail();

        return ApiResponse::item(['state' => $state, 'program' => $state->program()]);
    }

    /** GET /api/dunning/{account}/history — live state + archived episodes. */
    public function history(string $account): JsonResponse
    {
        return ApiResponse::item([
            'current' => DunningState::query()->where('account_id', $account)->first(),
            'archive' => \Illuminate\Support\Facades\DB::table('dunning_state_archive')->where('account_id', $account)->orderByDesc('archived_at')->get(),
        ]);
    }

    /** GET /api/dunning/pending-termination-review — the operator review queue (T-4). */
    public function pendingTerminationReview(Request $request): JsonResponse
    {
        $items = DunningState::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->where('status', DunningState::STATUS_PENDING_TERMINATION_REVIEW)
            ->orderBy('review_due_at')->get();

        return ApiResponse::item(['items' => $items]);
    }

    /** POST /api/dunning/{account}/advance — R-5(b) skip-to-next-level. */
    public function advance(Request $request, string $account): JsonResponse
    {
        $this->dunning->advance($account, $request->user()?->uid);

        return ApiResponse::item(DunningState::query()->where('account_id', $account)->first());
    }

    /** POST /api/dunning/{account}/clear-without-payment — R-5(c) write-off + recover. */
    public function clearWithoutPayment(Request $request, string $account): JsonResponse
    {
        $this->dunning->clearWithoutPayment($account, $request->user()?->uid);

        return ApiResponse::item(['accountId' => $account, 'status' => 'CLEARED']);
    }

    /** POST /api/dunning/{account}/force-terminate — confirm termination immediately. */
    public function forceTerminate(Request $request, string $account): JsonResponse
    {
        $this->dunning->forceTerminate($account, $request->user()?->uid);

        return ApiResponse::item(['accountId' => $account, 'status' => 'TERMINATING']);
    }

    /** POST /api/dunning/refresh-debt — re-pull outstanding debt (optionally one account). */
    public function refreshDebt(Request $request): JsonResponse
    {
        $account = $request->input('accountId');
        if ($account) {
            return ApiResponse::item($this->dunning->refreshDebt($account));
        }

        return ApiResponse::item($this->dunning->scan());
    }
}
