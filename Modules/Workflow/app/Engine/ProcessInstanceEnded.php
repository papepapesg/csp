<?php

namespace Modules\Workflow\Engine;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Workflow\Models\ProcessInstance;

/**
 * Fired (synchronously) when a process instance reaches a terminal state
 * (COMPLETED or FAILED). Owning modules listen to reconcile their own ledgers
 * (e.g. SUB-WF updates subscription_operation) without the engine knowing about
 * domain tables.
 */
class ProcessInstanceEnded
{
    use Dispatchable;

    public function __construct(public readonly ProcessInstance $instance) {}
}
