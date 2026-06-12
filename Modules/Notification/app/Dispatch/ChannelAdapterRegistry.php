<?php

namespace Modules\Notification\Dispatch;

use Modules\Notification\Dispatch\Adapters\EmailAdapter;
use Modules\Notification\Dispatch\Adapters\SmsAdapter;
use Modules\Notification\Models\ChannelOperatorConfig;

/**
 * Resolves the right ChannelAdapter for a (operator, channel) from
 * channel_operator_config.adapter_implementation, initializes it once, and caches it.
 * The implementation map is the registration point for new adapters/gateways: adding a
 * WhatsApp adapter or a second SMS gateway is one entry here, no change to the dispatcher.
 */
class ChannelAdapterRegistry
{
    /** adapter_implementation => adapter class. Operators pick by config row, never by code. */
    private array $implementations = [
        'smtp.default' => EmailAdapter::class,
        'sms.default' => SmsAdapter::class,
        'sms.africastalking' => SmsAdapter::class,
        'sms.beemafrica' => SmsAdapter::class,
        'sms.orange-sn' => SmsAdapter::class,
        'sms.twilio' => SmsAdapter::class,
        'smtp.transac' => EmailAdapter::class,
    ];

    /** @var array<string,ChannelAdapter> cached, initialized adapters keyed by operator|channel */
    private array $cache = [];

    public function register(string $implementation, string $adapterClass): void
    {
        $this->implementations[$implementation] = $adapterClass;
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
        $class = $this->implementations[$config->adapter_implementation] ?? null;
        if (! $class) {
            throw new AdapterConfigurationException("Unknown adapter implementation {$config->adapter_implementation}");
        }

        /** @var ChannelAdapter $adapter */
        $adapter = new $class();
        $adapter->initialize($config);

        return $this->cache[$key] = $adapter;
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
