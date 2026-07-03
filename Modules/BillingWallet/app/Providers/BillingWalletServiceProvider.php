<?php

namespace Modules\Billing\Wallet\Providers;

use App\Foundation\Events\OutboxEventPublished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Billing\Wallet\Console\WalletExpiryCommand;
use Modules\Billing\Wallet\Listeners\EvictPlmCatalogCache;

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

        // FOUNDATION_CACHE: evict the cached wallet-types copies this module's
        // WalletService keeps when a catalog row changes.
        Event::listen(OutboxEventPublished::class, [EvictPlmCatalogCache::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([WalletExpiryCommand::class]);
        }
    }
}
