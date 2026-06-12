<?php

namespace Modules\Notification\Dispatch;

use Modules\Notification\Models\ChannelOperatorConfig;

/**
 * Resolves the right ChannelAdapter for a (operator, channel) from
 * channel_operator_config.adapter_implementation, initializes it once, and caches it.
 *
 * The implementation map (adapter_implementation string -> adapter class) is CONFIG, not
 * code: sophix.notification.adapter_implementations. Adding a WhatsApp gateway is therefore
 * (1) write the adapter class, (2) add one config map entry, (3) insert the operator's
 * channel_operator_config row — no edit to this registry, the dispatcher, or any service.
 * register() additionally allows runtime registration (e.g. a module service provider
 * shipping its own adapter).
 */
class ChannelAdapterRegistry
{
    /** @var array<string,class-string<ChannelAdapter>> runtime-registered, wins over config */
    private array $registered = [];

    /** @var array<string,ChannelAdapter> cached, initialized adapters keyed by operator|channel */
    private array $cache = [];

    public function register(string $implementation, string $adapterClass): void
    {
        $this->registered[$implementation] = $adapterClass;
    }

    /**
     * Effective implementation map: runtime registrations over the config map. Read lazily
     * so config changes are honored regardless of when the singleton was constructed.
     *
     * @return array<string,class-string<ChannelAdapter>>
     */
    private function implementations(): array
    {
        return $this->registered + config('sophix.notification.adapter_implementations', []);
    }

    /**
     * @throws AdapterConfigurationException when the channel is unconfigured/disabled or the
     *                                        adapter implementation is unknown/invalid
     */
    public function for(string $operator, string $channel): ChannelAdapter
    {
        $key = $operator.'|'.$channel;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $config = ChannelOperatorConfig::resolve($operator, $channel);
        if (! $config || ! $config->enabled) {
            throw new AdapterConfigurationException("Channel {$channel} is not enabled for operator {$operator}");
        }

        return $this->cache[$key] = $this->make($config);
    }

    /**
     * Instantiate + initialize the adapter named by a config row's adapter_implementation.
     * Used by for() and by callers that build a transient default config (legacy send()).
     *
     * @throws AdapterConfigurationException
     */
    public function make(ChannelOperatorConfig $config): ChannelAdapter
    {
        $class = $this->implementations()[$config->adapter_implementation] ?? null;
        if (! $class) {
            throw new AdapterConfigurationException("Unknown adapter implementation {$config->adapter_implementation}");
        }

        /** @var ChannelAdapter $adapter */
        $adapter = new $class();
        $adapter->initialize($config);

        return $adapter;
    }

    /** The default adapter_implementation for a channel when no operator config row exists. */
    public function defaultImplementationFor(string $channel): ?string
    {
        return config("sophix.notification.default_adapter.{$channel}");
    }

    public function isConfigured(string $operator, string $channel): bool
    {
        try {
            $this->for($operator, $channel);

            return true;
        } catch (AdapterConfigurationException) {
            return false;
        }
    }
}
