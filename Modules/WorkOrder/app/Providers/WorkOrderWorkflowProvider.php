<?php

namespace Modules\WorkOrder\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Workflow\Engine\TaskRegistry;
use Modules\WorkOrder\Workflow\CaptureBindingsHandler;
use Modules\WorkOrder\Workflow\CheckWarrantyHandler;
use Modules\WorkOrder\Workflow\FinalizeSupportHandler;
use Modules\WorkOrder\Workflow\MarkEscalationHandler;
use Modules\WorkOrder\Workflow\ResolutionGateHandler;
use Modules\WorkOrder\Workflow\SiteVisitDecisionHandler;

/** Registers WO-01-FLOW-SUPPORT steps into the workflow toolbox. */
class WorkOrderWorkflowProvider extends ServiceProvider
{
    public function boot(): void
    {
        $registry = $this->app->make(TaskRegistry::class);
        $registry->register(CheckWarrantyHandler::class);
        $registry->register(SiteVisitDecisionHandler::class);
        $registry->register(ResolutionGateHandler::class);
        $registry->register(CaptureBindingsHandler::class);
        $registry->register(MarkEscalationHandler::class);
        $registry->register(FinalizeSupportHandler::class);
    }
}
