<?php

namespace Modules\Fulfillment\Providers;

use App\Foundation\Events\OutboxEventPublished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Fulfillment\Listeners\ResumeOrderOnInstallFinalized;
use Modules\Fulfillment\Listeners\ResumeOrderOnKycApproved;
use Modules\Fulfillment\Workflow\CompleteOrderHandler;
use Modules\Fulfillment\Workflow\CreateInstallWoHandler;
use Modules\Fulfillment\Workflow\CreateSubscriptionHandler;
use Modules\Fulfillment\Workflow\KycGateHandler;
use Modules\Fulfillment\Workflow\TriggerActivationHandler;
use Modules\Fulfillment\Workflow\ValidateOrderHandler;
use Modules\Workflow\Engine\TaskRegistry;

/** Registers the FUL-02 order-capture steps into the workflow toolbox. */
class FulfillmentWorkflowProvider extends ServiceProvider
{
    public function boot(): void
    {
        $registry = $this->app->make(TaskRegistry::class);
        $registry->register(ValidateOrderHandler::class);
        $registry->register(CreateSubscriptionHandler::class);
        $registry->register(CreateInstallWoHandler::class);
        $registry->register(KycGateHandler::class);
        $registry->register(TriggerActivationHandler::class);
        $registry->register(CompleteOrderHandler::class);

        Event::listen(OutboxEventPublished::class, [ResumeOrderOnInstallFinalized::class, 'handle']);
        Event::listen(OutboxEventPublished::class, [ResumeOrderOnKycApproved::class, 'handle']);
    }
}
