<?php

namespace Modules\Provisioning\Services;

use Illuminate\Contracts\Container\Container;
use Modules\Provisioning\Contracts\ProvisioningAdapter;
use Modules\Provisioning\Models\ProvisioningAdapterConfig;
use Modules\Provisioning\Models\ProvisioningCommand;

/**
 * PROV-INT-01 §10.2 adapter resolution. Picks the vendor adapter for a command by
 * its target: a GPON command resolves a Huawei adapter, a voice command a SIP
 * adapter, etc. (provisioning_adapter_config.adapter_class). When a target has no
 * configured binding, the deployment's default driver is used — so the stub keeps
 * every flow runnable, while real deployments route per protocol.
 */
class ProvisioningAdapterRegistry
{
    /** @var array<string,ProvisioningAdapter> resolved adapter instances by class */
    private array $instances = [];

    public function __construct(
        private readonly Container $container,
        private readonly ProvisioningAdapter $default,
    ) {}

    /** The adapter that should dispatch this command, based on its target. */
    public function forCommand(ProvisioningCommand $command): ProvisioningAdapter
    {
        return $this->forTarget((string) $command->operator_code, (string) $command->target_code);
    }

    /** The adapter bound to a target (used by dispatch and by reconciliation polling). */
    public function forTarget(string $operator, string $targetCode): ProvisioningAdapter
    {
        $config = ProvisioningAdapterConfig::forTarget($operator, $targetCode);
        if (! $config || ! $config->adapter_class) {
            return $this->default;
        }

        return $this->resolve($config->adapter_class);
    }

    private function resolve(string $adapterClass): ProvisioningAdapter
    {
        return $this->instances[$adapterClass] ??= (function () use ($adapterClass): ProvisioningAdapter {
            $adapter = $this->container->make($adapterClass);
            if (! $adapter instanceof ProvisioningAdapter) {
                // Misconfigured binding: never let a bad class silently break dispatch.
                return $this->default;
            }

            return $adapter;
        })();
    }
}
