<?php

namespace Modules\Notification\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Notification\Workflow\SendNotificationHandler;
use Modules\Workflow\Engine\TaskRegistry;

/** Registers Notification steps into the workflow toolbox. */
class NotificationWorkflowProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(TaskRegistry::class)->register(SendNotificationHandler::class);
    }
}
