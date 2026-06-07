<?php

namespace Modules\Workflow\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Workflow\Engine\WorkflowEngine;
use Modules\Workflow\Models\ActivityLog;
use Modules\Workflow\Models\ExternalTask;
use Modules\Workflow\Models\ProcessInstance;
use Modules\Workflow\Models\UserTask;

/**
 * IT-Ops / monitoring API: live process instances, end-to-end execution trace,
 * external/user task queues and incidents, message correlation.
 */
class WorkflowOpsController extends ApiController
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    public function instances(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = ProcessInstance::query()
            ->when($request->query('processKey'), fn ($q, $k) => $q->where('process_key', $k))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('businessKey'), fn ($q, $b) => $q->where('business_key', $b))
            ->when($request->query('operatorCode'), fn ($q, $o) => $q->where('operator_code', $o))
            ->orderByDesc('started_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    /** GET /api/workflow/instances/{instance} — full execution trace for replay. */
    public function instance(ProcessInstance $instance): JsonResponse
    {
        return ApiResponse::item([
            'instance' => $instance,
            'externalTasks' => ExternalTask::query()->where('instance_id', $instance->instance_id)->orderBy('created_at')->get(),
            'userTasks' => UserTask::query()->where('instance_id', $instance->instance_id)->orderBy('created_at')->get(),
            'trace' => ActivityLog::query()->where('instance_id', $instance->instance_id)->orderBy('id')->get(),
        ]);
    }

    /** Live task queues + incidents for the workers monitor. */
    public function tasks(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = ExternalTask::query()
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('topic'), fn ($q, $t) => $q->where('topic', $t))
            ->orderByDesc('created_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    public function userTasks(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = UserTask::query()
            ->when($request->query('candidateGroup'), fn ($q, $g) => $q->where('candidate_group', $g))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->orderByDesc('created_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    /** POST /api/workflow/user-tasks/{userTask}/complete */
    public function completeUserTask(Request $request, UserTask $userTask): JsonResponse
    {
        if ($userTask->status === UserTask::COMPLETED) {
            return ApiResponse::item($userTask);
        }
        $vars = $request->validate(['variables' => ['nullable', 'array']]);
        $this->engine->completeUserTask($userTask, $vars['variables'] ?? []);

        return ApiResponse::item($userTask->refresh());
    }

    /** POST /api/workflow/incidents/{task}/retry — re-queue a failed external task. */
    public function retryTask(ExternalTask $externalTask): JsonResponse
    {
        $externalTask->update(['status' => ExternalTask::CREATED, 'retries' => 3, 'worker_id' => null, 'locked_until' => null, 'error_message' => null]);

        return ApiResponse::item($externalTask);
    }

    /** POST /api/workflow/messages/correlate — deliver a catch message. */
    public function correlate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'messageName' => ['required', 'string'],
            'correlationKey' => ['nullable', 'string'],
            'variables' => ['nullable', 'array'],
        ]);
        $n = $this->engine->correlateMessage($data['messageName'], $data['correlationKey'] ?? null, $data['variables'] ?? []);

        return ApiResponse::item(['correlated' => $n]);
    }
}
