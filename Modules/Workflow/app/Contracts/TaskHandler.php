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

    // A handler MAY also declare `public function description(): string` to explain — in plain
    // language — what the step does ("what is this node for"); the studio inspector shows it.
    // It is optional: TaskRegistry falls back to a generated description when absent.

    public function handle(TaskContext $context): TaskResult;
}
