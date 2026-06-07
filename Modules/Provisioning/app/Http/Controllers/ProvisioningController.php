<?php

namespace Modules\Provisioning\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Provisioning\Models\ProvisioningCommand;
use Modules\Provisioning\Services\ProvisioningService;

/**
 * PROV-INT-01 NOC-facing command + reconciliation API.
 */
class ProvisioningController extends ApiController
{
    public function __construct(private readonly ProvisioningService $provisioning) {}

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

    /** POST /api/provisioning/reconcile — run desired-vs-observed reconciliation. */
    public function reconcile(): JsonResponse
    {
        return ApiResponse::item(['mismatches' => $this->provisioning->reconcile()]);
    }
}
