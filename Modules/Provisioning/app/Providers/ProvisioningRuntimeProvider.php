<?php

namespace Modules\Provisioning\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Provisioning\Adapters\StubProvisioningAdapter;
use Modules\Provisioning\Contracts\ProvisioningAdapter;
use Modules\Provisioning\Workflow\ActivateServiceHandler;
use Modules\Workflow\Engine\TaskRegistry;

/**
 * Binds the provisioning adapter (driver selectable via SOPHIX_PROVISIONING_DRIVER)
 * and registers the provisioning step into the workflow toolbox.
 */
class ProvisioningRuntimeProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProvisioningAdapter::class, function () {
            // return new HuaweiNceAdapter() when SOPHIX_PROVISIONING_DRIVER=nce, etc.
            return match (config('sophix.provisioning_driver', 'stub')) {
                default => new StubProvisioningAdapter,
            };
        });
    }

    public function boot(): void
    {
        $this->app->make(TaskRegistry::class)->register(ActivateServiceHandler::class);
    }
}
