<?php

namespace Modules\Workflow\Console;

use Illuminate\Console\Command;
use Modules\ItOps\Support\Heartbeat;
use Modules\Workflow\Engine\WorkflowEngine;
use Modules\Workflow\Models\ExternalTask;

/**
 * Scheduler tick: fire due timers and release expired task locks so stuck tasks
 * become re-pollable. Runs every minute (FOUNDATION_CAMUNDA job executor role).
 */
class WorkflowTickCommand extends Command
{
    protected $signature = 'sophix:workflow:tick';

    protected $description = 'Fire due workflow timers and release expired external-task locks';

    public function handle(WorkflowEngine $engine): int
    {
        $fired = $engine->fireDueTimers();

        $released = ExternalTask::query()
            ->where('status', ExternalTask::LOCKED)
            ->where('locked_until', '<', now())
            ->update(['status' => ExternalTask::CREATED, 'worker_id' => null, 'locked_until' => null]);

        Heartbeat::ping('scheduler', null, ['timersFired' => $fired, 'locksReleased' => $released]);

        $this->info("timers fired: {$fired}, locks released: {$released}");

        return self::SUCCESS;
    }
}
