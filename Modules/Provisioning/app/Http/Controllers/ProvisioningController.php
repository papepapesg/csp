<?php

namespace Modules\Provisioning\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Provisioning\Models\ProvisioningCommand;
use Modules\Provisioning\Models\ProvisioningReconciliationItem;
use Modules\Provisioning\Models\ProvisioningReconciliationRun;
use Modules\Provisioning\Services\ProvisioningService;
use Modules\Provisioning\Services\ReconciliationService;

/**
 * PROV-INT-01 NOC-facing command + reconciliation API.
 */
class ProvisioningController extends ApiController
{
    public function __construct(
        private readonly ProvisioningService $provisioning,
        private readonly ReconciliationService $reconciliation,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = ProvisioningCommand::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('subscriptionId'), fn ($q, $sid) => $q->where('subscription_id', $sid))
            ->orderByDesc('created_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function show(ProvisioningCommand $provisioningCommand): JsonResponse
    {
        return ApiResponse::item($provisioningCommand);
    }

    /** POST /api/provisioning/commands/{cmd}/retry — re-dispatch a failed command. */
    public function retry(ProvisioningCommand $provisioningCommand): JsonResponse
    {
        return ApiResponse::item($this->provisioning->dispatch($provisioningCommand));
    }

    /** POST /api/provisioning/reconcile — quick command-level reconciliation. */
    public function reconcile(): JsonResponse
    {
        return ApiResponse::item(['mismatches' => $this->provisioning->reconcile()]);
    }

    /** POST /api/provisioning/reconciliation/run — full desired-vs-observed run. */
    public function reconciliationRun(Request $request): JsonResponse
    {
        $run = $this->reconciliation->run(
            targetCode: $request->input('targetCode'),
            operator: $request->input('operatorCode', Context::operatorCode()),
        );

        return ApiResponse::item($run->load('items'));
    }

    /** GET /api/provisioning/reconciliation/runs — recent reconciliation runs. */
    public function reconciliationRuns(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = ProvisioningReconciliationRun::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->orderByDesc('started_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    /** GET /api/provisioning/reconciliation/items — open (or filtered) mismatches. */
    public function reconciliationItems(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = ProvisioningReconciliationItem::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('targetCode'), fn ($q, $t) => $q->where('target_code', $t))
            ->orderByDesc('created_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    /** POST /api/provisioning/reconciliation/items/{item}/force-sync — NOC force-sync. */
    public function forceSync(Request $request, ProvisioningReconciliationItem $item): JsonResponse
    {
        return ApiResponse::item($this->reconciliation->forceSync($item, $request->user()?->uid));
    }
}
