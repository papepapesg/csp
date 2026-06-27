<?php

namespace Modules\Notification\Icn;

use Modules\Notification\Models\Icn\StaffNotificationAdapterBinding;

/**
 * Resolves the StaffChannelAdapter for a (operator, channel) from the binding's adapter_impl
 * (§7.0 step 3), initializing it from the binding's config_jsonb. The adapter_impl -> class
 * map is CONFIG (sophix.icn.adapter_implementations), so adding a provider is a class + a
 * config entry + a binding row — no registry edit. register() allows runtime registration.
 */
class StaffAdapterRegistry
{
    /** @var array<string,class-string<StaffChannelAdapter>> runtime registrations (win over config) */
    private array $registered = [];

    /** @var array<string,StaffChannelAdapter> cached, initialized adapters keyed by operator|channel */
    private array $cache = [];

    public function register(string $implCode, string $adapterClass): void
    {
        $this->registered[$implCode] = $adapterClass;
    }

    /** @return array<string,class-string<StaffChannelAdapter>> */
    private function implementations(): array
    {
        return $this->registered + config('sophix.icn.adapter_implementations', []);
    }

    /** Implementation codes registered in this deployment (for GET /api/icn/adapter-registry). */
    public function registeredImpls(): array
    {
        return array_keys($this->implementations());
    }

    /**
     * Resolve + init the adapter for a binding. Returns null when the adapter_impl is unknown
     * (caller marks the delivery TERMINALLY_FAILED with ADAPTER_NOT_REGISTERED, R-ICN-01-D-17).
     */
    public function forBinding(StaffNotificationAdapterBinding $binding): ?StaffChannelAdapter
    {
        $key = $binding->operator_code.'|'.$binding->channel;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $class = $this->implementations()[$binding->adapter_impl] ?? null;
        if (! $class) {
            return null;
        }
        /** @var StaffChannelAdapter $adapter */
        $adapter = new $class();
        $adapter->init($binding->config_jsonb ?? []);

        return $this->cache[$key] = $adapter;
    }
}
