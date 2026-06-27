<?php

namespace Modules\Osr\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Osr\Swap\Workflow\CompleteSwapHandler;
use Modules\Osr\Swap\Workflow\CompleteWithoutRecoveryHandler;
use Modules\Osr\Swap\Workflow\CreateSwapWorkOrderHandler;
use Modules\Osr\Swap\Workflow\FailSwapHandler;
use Modules\Osr\Swap\Workflow\ProvisionSwapHandler;
use Modules\Osr\Swap\Workflow\RecoverSourceHandler;
use Modules\Osr\Swap\Workflow\ReserveSlotHandler;
use Modules\Osr\Swap\Workflow\ValidateSwapEligibilityHandler;
use Modules\Workflow\Engine\TaskRegistry;

/** Registers OSR-RMA-01 swap steps into the workflow toolbox. */
class OsrWorkflowProvider extends ServiceProvider
{
    public function boot(): void
    {
        $registry = $this->app->make(TaskRegistry::class);
        $registry->register(ValidateSwapEligibilityHandler::class);
        $registry->register(ReserveSlotHandler::class);
        $registry->register(CreateSwapWorkOrderHandler::class);
        $registry->register(RecoverSourceHandler::class);
        $registry->register(ProvisionSwapHandler::class);
        $registry->register(CompleteSwapHandler::class);
        $registry->register(CompleteWithoutRecoveryHandler::class);
        $registry->register(FailSwapHandler::class);
    }
}
