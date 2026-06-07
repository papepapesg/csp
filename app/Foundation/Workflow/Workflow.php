<?php

namespace App\Foundation\Workflow;

/**
 * A long-running workflow (FOUNDATION_CAMUNDA, native driver).
 *
 * Implementations encode the orchestration for one operation type. handle() runs
 * inside a queued worker; it may execute steps synchronously, chain further
 * queued jobs, wait on external callbacks, or compensate on failure. The
 * Operation row is the durable state, mirroring a Camunda process instance.
 */
interface Workflow
{
    /** Stable key / process definition id, e.g. SUBSCRIPTION_ACTIVATE. */
    public function key(): string;

    /** Advance the operation. Throw to mark it FAILED (with compensation). */
    public function handle(Operation $operation): void;
}
