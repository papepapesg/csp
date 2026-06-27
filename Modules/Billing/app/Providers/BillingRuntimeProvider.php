<?php

namespace Modules\Billing\Providers;

use App\Foundation\Events\OutboxEventPublished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Billing\Invoicing\Console\CycleCloseCommand;
use Modules\Billing\Invoicing\Console\GenerationFailureRetryCommand;
use Modules\Billing\Invoicing\Console\ProFormaScanCommand;
use Modules\Billing\Invoicing\Console\RunCycleBillingCommand;
use Modules\Billing\Invoicing\Listeners\ApplyCreditBalanceOnInvoice;
use Modules\Billing\Invoicing\Listeners\RetryFrozenCycleOnTopup;

/** Billing core runtime wiring — Invoicing + Payments listeners and commands (the money core). */
class BillingRuntimeProvider extends ServiceProvider
{
    public function boot(): void
    {
        // BIL-03: a wallet top-up may unfreeze a prepaid cycle that missed payment.
        Event::listen(OutboxEventPublished::class, [RetryFrozenCycleOnTopup::class, 'handle']);

        // BIL-01-PAY-01 OV-2: a newly issued invoice auto-draws any account credit balance.
        Event::listen(OutboxEventPublished::class, [ApplyCreditBalanceOnInvoice::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([RunCycleBillingCommand::class, CycleCloseCommand::class, ProFormaScanCommand::class, GenerationFailureRetryCommand::class,
                \Modules\Billing\Console\OpsStatusCommand::class]);
        }
    }
}
