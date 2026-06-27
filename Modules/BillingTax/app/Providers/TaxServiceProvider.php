<?php

namespace Modules\Billing\Tax\Providers;

use App\Foundation\Events\OutboxEventPublished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Billing\Tax\Adapters\StubTaxGateway;
use Modules\Billing\Tax\Console\TaxRetryScanCommand;
use Modules\Billing\Tax\Console\TaxSignScanCommand;
use Modules\Billing\Tax\Contracts\TaxGateway;
use Modules\Billing\Tax\Listeners\TaxEventBridge;
use Modules\Billing\Tax\TaxSignerRegistry;

/** BIL-02-TAX-01 tax module bootstrap — owns its schema, routes, fiscalisation gateway and signer. */
class TaxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);

        // Fiscalisation gateway (driver via SOPHIX_TAX_DRIVER) + signer registry (cached signers).
        $this->app->singleton(TaxGateway::class, function () {
            return match (config('sophix.tax_driver', 'stub')) {
                default => new StubTaxGateway,
            };
        });
        $this->app->singleton(TaxSignerRegistry::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');

        // BIL-02-TAX-01: every payment moment generates a tax invoice when the operator enabled it.
        Event::listen(OutboxEventPublished::class, [TaxEventBridge::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([TaxSignScanCommand::class, TaxRetryScanCommand::class]);
        }
    }
}
