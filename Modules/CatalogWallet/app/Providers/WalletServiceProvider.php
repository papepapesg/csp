<?php

namespace Modules\Catalog\Wallet\Providers;

use Illuminate\Support\ServiceProvider;

/** Wallet module bootstrap — owns its migrations, routes and workers. Namespace unchanged. */
class WalletServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
    }
}
