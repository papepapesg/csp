<?php

namespace Modules\WorkOrder\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Workflow\Engine\TaskRegistry;
use Modules\WorkOrder\Console\OpsStatusCommand;
use Modules\WorkOrder\Console\WorkOrderFixCommand;
use Modules\WorkOrder\Console\WorkOrderShowCommand;
use Modules\WorkOrder\Workflow\CaptureBindingsHandler;
use Modules\WorkOrder\Workflow\CheckWarrantyHandler;
use Modules\WorkOrder\Workflow\FinalizeShiftingHandler;
use Modules\WorkOrder\Workflow\FinalizeSupportHandler;
use Modules\WorkOrder\Workflow\MarkEscalationHandler;
use Modules\WorkOrder\Workflow\MarkPhaseHandler;
use Modules\WorkOrder\Workflow\ResolutionGateHandler;
use Modules\WorkOrder\Workflow\SiteVisitDecisionHandler;

/** Registers WO-01-FLOW-SUPPORT + WO-01-FLOW-SHIFTING steps into the workflow toolbox. */
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
        $registry->register(MarkPhaseHandler::class);
        $registry->register(FinalizeShiftingHandler::class);

        // WO-01 ops console: read-only review + break-glass safe-correction commands.
        if ($this->app->runningInConsole()) {
            $this->commands([OpsStatusCommand::class, WorkOrderShowCommand::class, WorkOrderFixCommand::class]);
        }
    }
}
