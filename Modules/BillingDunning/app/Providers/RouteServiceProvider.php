<?php

namespace Modules\Billing\Dunning\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')->prefix('api')->name('api.')
            ->group(dirname(__DIR__, 2).'/routes/api.php');
    }
}
