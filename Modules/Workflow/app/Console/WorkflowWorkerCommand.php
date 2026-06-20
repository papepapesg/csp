<?php

namespace Modules\Workflow\Console;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\ItOps\Support\Heartbeat;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Engine\TaskRegistry;
use Modules\Workflow\Engine\WorkflowEngine;
use Modules\Workflow\Models\ExternalTask;
use Modules\Workflow\Models\ProcessInstance;

/**
 * Workflow external-task worker (CAM-WORKER-*). Polls for CREATED service tasks
 * on registered topics, fetch-and-locks them, runs the toolbox handler, and
 * completes/fails them through the engine. Idempotent and retry-safe.
 *
 *   sophix:workflow:work            # run continuously (polling)
 *   sophix:workflow:work --once     # drain currently-runnable tasks then exit
 */
class WorkflowWorkerCommand extends Command
{
    protected $signature = 'sophix:workflow:work {--once} {--max=25} {--sleep=2}';

    protected $description = 'Run the workflow external-task worker (fetchAndLock pattern)';

    public function handle(TaskRegistry $registry, WorkflowEngine $engine): int
    {
        $workerId = 'wf-worker-'.Id::make('w');
        $topics = $registry->topics();

        if (empty($topics)) {
            $this->warn('No task handlers registered.');

            return self::SUCCESS;
        }

        do {
            // IT-Ops control gate (checked first so a pending PAUSE keeps a restarted
            // worker down, and a RESTART exits before claiming more work). --once drains
            // regardless — it is a bounded operation, not a long-running service.
            if (! $this->option('once') && Heartbeat::shouldStop('workflow-worker')) {
                $this->info('Stop/pause requested via IT-Ops — exiting.');
                break;
            }

            $processed = $this->drainOnce($registry, $engine, $workerId, $topics, (int) $this->option('max'));

            // IT-Ops liveness.
            Heartbeat::ping('workflow-worker', $workerId, ['lastBatch' => $processed]);

            if ($this->option('once')) {
                if ($processed === 0) {
                    break;
                }

                continue;
            }
            if ($processed === 0) {
                sleep((int) $this->option('sleep'));
            }
        } while (true);

        return self::SUCCESS;
    }

    /** @param array<string> $topics */
    private function drainOnce(TaskRegistry $registry, WorkflowEngine $engine, string $workerId, array $topics, int $max): int
    {
        // fetchAndLock: claim a batch atomically.
        $taskIds = DB::transaction(function () use ($topics, $max, $workerId) {
            $rows = ExternalTask::query()
                ->where('status', ExternalTask::CREATED)
                ->whereIn('topic', $topics)
                ->orderBy('created_at')
                ->limit($max)
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                $row->update([
                    'status' => ExternalTask::LOCKED,
                    'worker_id' => $workerId,
                    'locked_until' => now()->addSeconds(60),
                ]);
            }

            return $rows->pluck('task_id')->all();
        });

        foreach ($taskIds as $taskId) {
            $task = ExternalTask::query()->find($taskId);
            if (! $task || $task->status !== ExternalTask::LOCKED) {
                continue;
            }

            $instance = ProcessInstance::query()->find($task->instance_id);
            $handler = $registry->resolve($task->topic);

            if (! $instance || ! $handler) {
                $engine->failExternalTask($task, 'No handler/instance for topic '.$task->topic, retryable: false);

                continue;
            }

            if ($instance->correlation_id) {
                Context::setCorrelationId($instance->correlation_id);
            }
            Context::setOperatorCode($task->operator_code);

            try {
                $result = $handler->handle(new TaskContext($instance, $task));
                if ($result->ok) {
                    $engine->completeExternalTask($task, $result->variables);
                } else {
                    $engine->failExternalTask($task, $result->errorMessage ?? 'task failed', $result->retryable);
                }
            } catch (\Throwable $e) {
                $engine->failExternalTask($task, $e->getMessage(), retryable: true);
            }
        }

        return count($taskIds);
    }
}
