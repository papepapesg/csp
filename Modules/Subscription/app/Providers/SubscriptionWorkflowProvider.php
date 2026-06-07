<?php

namespace Modules\Subscription\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Subscription\Workflow\ActivateHandler;
use Modules\Subscription\Workflow\SyncOperationFromProcess;
use Modules\Subscription\Workflow\TerminateHandler;
use Modules\Subscription\Workflow\ValidateActivationHandler;
use Modules\Workflow\Engine\ProcessInstanceEnded;
use Modules\Workflow\Engine\TaskRegistry;

/** Registers Subscription steps into the workflow toolbox. */
class SubscriptionWorkflowProvider extends ServiceProvider
{
    public function boot(): void
    {
        $registry = $this->app->make(TaskRegistry::class);
        $registry->register(ValidateActivationHandler::class);
        $registry->register(ActivateHandler::class);
        $registry->register(TerminateHandler::class);

        Event::listen(ProcessInstanceEnded::class, [SyncOperationFromProcess::class, 'handle']);
    }
}
