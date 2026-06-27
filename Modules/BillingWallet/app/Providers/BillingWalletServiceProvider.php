<?php

namespace Modules\Billing\Wallet\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Billing\Wallet\Console\WalletExpiryCommand;

/**
 * BillingWallet (BIL-05) module bootstrap. Self-contained: owns its migrations, routes,
 * and the wallet-expiry worker. The wallet namespace stays Modules\Billing\Wallet\* so no
 * consumer reference changes — only the module now owns the code.
 */
class BillingWalletServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([WalletExpiryCommand::class]);
        }
    }
}
