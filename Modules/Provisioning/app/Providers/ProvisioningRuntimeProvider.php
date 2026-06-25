<?php

namespace Modules\Provisioning\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Provisioning\Adapters\StubProvisioningAdapter;
use Modules\Provisioning\Console\ReconcileCommand;
use Modules\Provisioning\Contracts\ProvisioningAdapter;
use Modules\Provisioning\Services\ProvisioningAdapterRegistry;
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
        // The deployment's DEFAULT driver (used when a target has no adapter binding).
        $this->app->singleton(ProvisioningAdapter::class, function () {
            // return new HuaweiNceAdapter() when SOPHIX_PROVISIONING_DRIVER=nce, etc.
            return match (config('sophix.provisioning_driver', 'stub')) {
                default => new StubProvisioningAdapter,
            };
        });

        // Per-target adapter resolution (PROV-INT-01 §10.2): picks the vendor adapter
        // for each command's target from provisioning_adapter_config, falling back to
        // the default driver above.
        $this->app->singleton(ProvisioningAdapterRegistry::class, function ($app) {
            return new ProvisioningAdapterRegistry($app, $app->make(ProvisioningAdapter::class));
        });
    }

    public function boot(): void
    {
        $this->app->make(TaskRegistry::class)->register(ActivateServiceHandler::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileCommand::class, \Modules\Provisioning\Console\PollAsyncCommand::class,
                \Modules\Provisioning\Console\OpsStatusCommand::class, \Modules\Provisioning\Console\CommandShowCommand::class, \Modules\Provisioning\Console\OpsFixCommand::class]);
        }
    }
}
