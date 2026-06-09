<?php

namespace Modules\Subscription\Providers;

use App\Foundation\Events\OutboxEventPublished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Subscription\Console\OperationTimeoutCommand;
use Modules\Subscription\Listeners\ConfirmBillingIntentOnPayment;
use Modules\Subscription\Listeners\ConfirmPrepaidIntentOnTopup;
use Modules\Subscription\Workflow\ActivateHandler;
use Modules\Subscription\Workflow\BillingIntentHandler;
use Modules\Subscription\Workflow\ChangeHomePassHandler;
use Modules\Subscription\Workflow\ChangePackageHandler;
use Modules\Subscription\Workflow\CreateShiftingWoHandler;
use Modules\Subscription\Workflow\EnterPendingStatusHandler;
use Modules\Subscription\Workflow\EquipmentPickupHandler;
use Modules\Subscription\Workflow\FulfillmentCallHandler;
use Modules\Subscription\Workflow\PauseHandler;
use Modules\Subscription\Workflow\PutActiveRestrictionsHandler;
use Modules\Subscription\Workflow\ResumeHandler;
use Modules\Subscription\Workflow\SuspendHandler;
use Modules\Subscription\Workflow\SyncOperationFromProcess;
use Modules\Subscription\Workflow\TerminateHandler;
use Modules\Subscription\Workflow\ValidateActivationHandler;
use Modules\Subscription\Workflow\ValidateHomePassChangeHandler;
use Modules\Subscription\Workflow\ValidateOperationHandler;
use Modules\Subscription\Workflow\ValidatePackageChangeHandler;
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
        $registry->register(ValidateOperationHandler::class);
        $registry->register(PauseHandler::class);
        $registry->register(ResumeHandler::class);
        $registry->register(SuspendHandler::class);
        $registry->register(PutActiveRestrictionsHandler::class);
        $registry->register(EnterPendingStatusHandler::class);
        $registry->register(FulfillmentCallHandler::class);
        $registry->register(BillingIntentHandler::class);
        $registry->register(ValidatePackageChangeHandler::class);
        $registry->register(ChangePackageHandler::class);
        $registry->register(ValidateHomePassChangeHandler::class);
        $registry->register(ChangeHomePassHandler::class);
        $registry->register(EquipmentPickupHandler::class);
        $registry->register(CreateShiftingWoHandler::class);

        Event::listen(ProcessInstanceEnded::class, [SyncOperationFromProcess::class, 'handle']);
        Event::listen(OutboxEventPublished::class, [ConfirmBillingIntentOnPayment::class, 'handle']);
        Event::listen(OutboxEventPublished::class, [ConfirmPrepaidIntentOnTopup::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([OperationTimeoutCommand::class]);
        }
    }
}
