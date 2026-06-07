<?php

namespace Modules\ItOps\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\ItOps\Models\ServiceControl;
use Modules\ItOps\Models\ServiceHeartbeat;
use Modules\ItOps\Models\SystemLog;

/**
 * IT-Ops console API: searchable logs, service/worker status, and control.
 */
class ItOpsController extends ApiController
{
    /** GET /api/itops/logs?level=&q=&correlationId=&from=&to= */
    public function logs(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = SystemLog::query()
            ->when($request->query('level'), fn ($q, $l) => $q->whereIn('level', explode(',', strtolower($l))))
            ->when($request->query('channel'), fn ($q, $c) => $q->where('channel', $c))
            ->when($request->query('correlationId'), fn ($q, $c) => $q->where('correlation_id', $c))
            ->when($request->query('q'), fn ($q, $term) => $q->where('message', 'ilike', "%{$term}%"))
            ->when($request->query('from'), fn ($q, $f) => $q->where('logged_at', '>=', $f))
            ->when($request->query('to'), fn ($q, $t) => $q->where('logged_at', '<=', $t))
            ->orderByDesc('id')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    /** GET /api/itops/services — worker/service status with up/down + metrics. */
    public function services(): JsonResponse
    {
        $threshold = now()->subSeconds(120);
        $services = ServiceHeartbeat::query()->orderBy('service')->get()->map(fn ($s) => [
            'service' => $s->service,
            'instanceId' => $s->instance_id,
            'status' => $s->last_seen_at && $s->last_seen_at->gt($threshold) ? 'UP' : 'DOWN',
            'lastSeenAt' => $s->last_seen_at?->toIso8601String(),
            'metrics' => $s->metrics,
        ]);

        return ApiResponse::item([
            'services' => $services,
            'queues' => [
                'workflowTasksCreated' => DB::table('workflow_external_task')->where('status', 'CREATED')->count(),
                'workflowIncidents' => DB::table('workflow_external_task')->where('status', 'INCIDENT')->count(),
                'outboxUnpublished' => DB::table('outbox_events')->whereNull('published_at')->count(),
            ],
        ]);
    }

    /** POST /api/itops/services/{service}/restart — request a graceful restart. */
    public function restart(Request $request, string $service): JsonResponse
    {
        $control = ServiceControl::query()->updateOrCreate(
            ['service' => $service],
            ['command' => 'RESTART', 'requested_by' => $request->user()?->uid, 'requested_at' => now(), 'acknowledged_at' => null],
        );

        return ApiResponse::accepted(entityId: $service, nextAction: 'AWAIT_ACK', extra: ['control' => $control], status: 202);
    }
}
