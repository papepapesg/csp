<?php

namespace Modules\Workflow\Contracts;

/**
 * A reusable workflow step (the "toolbox"). Modules implement handlers and
 * register them by topic; the studio composes flows from these topics without
 * code. Handlers MUST be idempotent — the engine may retry (CAM-WORKER-1).
 */
interface TaskHandler
{
    /** Stable topic, one per business action, e.g. 'sub.activate' (CAM-WORKER-3). */
    public function topic(): string;

    /** Human-friendly label + category for the studio toolbox palette. */
    public function label(): string;

    public function handle(TaskContext $context): TaskResult;
}
