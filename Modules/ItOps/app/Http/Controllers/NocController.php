<?php

namespace Modules\ItOps\Http\Controllers;

use App\Foundation\Events\Outbox\OutboxEvent;
use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ItOps\Models\ServiceControl;
use Modules\ItOps\Models\ServiceHeartbeat;
use Modules\ItOps\Models\SystemLog;
use Modules\Provisioning\Models\ProvisioningCommand;
use Modules\Provisioning\Models\ProvisioningReconciliationItem;
use Modules\Ticketing\Models\Ticket;
use Modules\Workflow\Models\ExternalTask;
use Modules\Workflow\Models\ProcessInstance;

/**
 * NOC console API: one overview of everything running (service heartbeats +
 * start/stop control, workflow incidents, provisioning mismatches, outbox backlog,
 * SLA-overdue tickets) and END-TO-END TRACES — every platform record carries a
 * correlation_id (FOUNDATION pattern), so one key reconstructs the full journey
 * across outbox events, workflow instances/tasks, provisioning commands, system
 * logs and notifications.
 */
class NocController extends ApiController
{
    /** GET /api/noc/overview — the single pane of glass. */
    public function overview(): JsonResponse
    {
        return ApiResponse::item([
            'services' => ServiceHeartbeat::query()->orderBy('service')->get(),
            'runningInstances' => ProcessInstance::query()->where('status', ProcessInstance::RUNNING)->count(),
            'workflowIncidents' => ExternalTask::query()->whereIn('status', [ExternalTask::FAILED, ExternalTask::INCIDENT])->count(),
            'provisioningMismatches' => ProvisioningReconciliationItem::query()->where('status', 'OPEN')->count(),
            'outboxBacklog' => OutboxEvent::query()->whereNull('published_at')->count(),
            'slaOverdueTickets' => Ticket::query()
                ->whereNotIn('status', [Ticket::RESOLVED, Ticket::CLOSED, Ticket::CANCELLED])
                ->whereNotNull('sla_due_at')->where('sla_due_at', '<', now())->count(),
        ]);
    }

    /** GET /api/noc/sla-overdue — the tickets breaching SLA right now. */
    public function slaOverdue(): JsonResponse
    {
        return ApiResponse::item(['items' => Ticket::query()
            ->whereNotIn('status', [Ticket::RESOLVED, Ticket::CLOSED, Ticket::CANCELLED])
            ->whereNotNull('sla_due_at')->where('sla_due_at', '<', now())
            ->orderBy('sla_due_at')->limit(100)
            ->get(['ticket_id', 'subject', 'priority', 'status', 'queue', 'assignee_id', 'sla_due_at'])]);
    }

    /**
     * GET /api/noc/trace?key=… — end-to-end trace. The key may be a correlation id
     * OR a business key (subscription/order/operation/ticket id): we match both,
     * then expand to the correlation ids found so the whole journey is captured.
     */
    public function trace(Request $request): JsonResponse
    {
        $key = (string) $request->validate(['key' => ['required', 'string', 'max:128']])['key'];

        $events = OutboxEvent::query()
            ->where(fn ($q) => $q->where('correlation_id', $key)->orWhere('aggregate_id', $key))
            ->orderBy('created_at')->limit(200)->get();

        // Expand: correlation ids discovered via the key's events.
        $correlations = $events->pluck('correlation_id')->filter()->unique()->push($key)->all();

        $instances = ProcessInstance::query()
            ->where(fn ($q) => $q->where('business_key', $key)->orWhereIn('correlation_id', $correlations))
            ->orderBy('created_at')->limit(20)->get();
        $tasks = ExternalTask::query()->whereIn('instance_id', $instances->pluck('instance_id'))
            ->orderBy('created_at')->limit(200)->get();

        $commands = ProvisioningCommand::query()
            ->where(fn ($q) => $q->where('subscription_id', $key)->orWhereIn('correlation_id', $correlations))
            ->orderBy('created_at')->limit(50)->get();

        $logs = SystemLog::query()->whereIn('correlation_id', $correlations)
            ->orderBy('logged_at')->limit(200)->get();

        $notifications = \Modules\Notification\Models\Notification::query()
            ->where('reference', $key)->orderBy('created_at')->limit(50)->get();

        // One merged, time-ordered narrative across every subsystem.
        $timeline = collect()
            ->concat($events->map(fn ($e) => ['at' => $e->created_at, 'source' => 'EVENT', 'label' => $e->event_type, 'detail' => $e->aggregate_id]))
            ->concat($instances->map(fn ($i) => ['at' => $i->created_at, 'source' => 'WORKFLOW', 'label' => "{$i->process_key} {$i->status}", 'detail' => $i->instance_id]))
            ->concat($tasks->map(fn ($t) => ['at' => $t->created_at, 'source' => 'TASK', 'label' => "{$t->topic} {$t->status}", 'detail' => $t->node_id]))
            ->concat($commands->map(fn ($c) => ['at' => $c->created_at, 'source' => 'PROVISIONING', 'label' => "{$c->action} {$c->status}", 'detail' => $c->target_code]))
            ->concat($notifications->map(fn ($n) => ['at' => $n->created_at, 'source' => 'NOTIFICATION', 'label' => "{$n->channel} {$n->status}", 'detail' => $n->template_code]))
            ->concat($logs->map(fn ($l) => ['at' => $l->logged_at, 'source' => 'LOG', 'label' => strtoupper($l->level), 'detail' => $l->message ?? $l->channel]))
            ->sortBy('at')->values();

        return ApiResponse::item([
            'key' => $key,
            'correlationIds' => $correlations,
            'timeline' => $timeline,
            'events' => $events,
            'instances' => $instances,
            'tasks' => $tasks,
            'provisioningCommands' => $commands,
            'notifications' => $notifications,
            'logs' => $logs,
        ]);
    }

    /** POST /api/noc/services/{service}/stop|start — pause/resume a platform worker. */
    public function stop(Request $request, string $service): JsonResponse
    {
        return $this->control($request, $service, 'PAUSE', 'DOWN');
    }

    public function start(Request $request, string $service): JsonResponse
    {
        return $this->control($request, $service, 'RESUME', 'UP');
    }

    private function control(Request $request, string $service, string $command, string $status): JsonResponse
    {
        ServiceControl::query()->updateOrCreate(
            ['service' => $service],
            ['command' => $command, 'requested_by' => $request->user()?->uid, 'requested_at' => now()],
        );
        ServiceHeartbeat::query()->updateOrCreate(
            ['service' => $service],
            ['status' => $status, 'last_seen_at' => now()],
        );

        return ApiResponse::item(['service' => $service, 'command' => $command, 'status' => $status]);
    }
}
