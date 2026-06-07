<?php

namespace Modules\Billing\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Billing\Adapters\StubTaxGateway;
use Modules\Billing\Console\DunningRunCommand;
use Modules\Billing\Contracts\TaxGateway;

/** Binds the tax-fiscalisation gateway (driver via SOPHIX_TAX_DRIVER). */
class BillingRuntimeProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DunningRunCommand::class]);
        }
    }

    public function register(): void
    {
        $this->app->singleton(TaxGateway::class, function () {
            return match (config('sophix.tax_driver', 'stub')) {
                default => new StubTaxGateway,
            };
        });
    }
}
