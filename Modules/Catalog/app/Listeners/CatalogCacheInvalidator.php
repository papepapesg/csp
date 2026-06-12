<?php

namespace Modules\Catalog\Listeners;

use App\Foundation\Cache\SophixCache;
use App\Foundation\Events\OutboxEventPublished;
use Modules\Catalog\Events\CatalogEvents;
use Modules\Catalog\Support\CatalogCacheKeys;

/**
 * Event-driven catalog cache invalidation (the "CatalogChanged → evicts" hook).
 * A stale sales-package list or voice tariff directly harms onboarding/billing, so
 * any lifecycle change that affects a cached read model evicts the operator's coarse
 * snapshot here (R-SIP-02-12; PLM-CFG-07 §10 "RAT-01 invalidates tariff cache").
 * The next cache-aside read rebuilds from the authoritative tables.
 */
class CatalogCacheInvalidator
{
    /** Event types that make the SIP-02 sellable-package read model stale. */
    private const AVAILABILITY_EVENTS = [
        CatalogEvents::PACKAGE_ACTIVATED,
        CatalogEvents::PACKAGE_AVAILABILITY_CHANGED,
        CatalogEvents::PACKAGE_END_OF_SALE,
        CatalogEvents::PACKAGE_RETIRED,
    ];

    /** Event types that make the PLM-CFG-07 voice-tariff snapshot stale. */
    private const VOICE_EVENTS = [
        CatalogEvents::VOICE_TARIFF_PLAN_ACTIVATED,
        CatalogEvents::VOICE_TARIFF_PLAN_RETIRED,
        CatalogEvents::VOICE_TARIFF_RATE_CHANGED,
        CatalogEvents::VOICE_DESTINATION_PREFIX_CHANGED,
        CatalogEvents::VOICE_TARIFF_BINDING_CHANGED,
    ];

    public function __construct(private readonly SophixCache $cache) {}

    public function handle(OutboxEventPublished $published): void
    {
        $type = $published->event->event_type;
        $operator = $published->event->payload['operatorCode'] ?? null;
        if ($operator === null) {
            return;
        }

        if (in_array($type, self::AVAILABILITY_EVENTS, true)) {
            $this->cache->evict(...CatalogCacheKeys::availablePackages($operator));
        }
        if (in_array($type, self::VOICE_EVENTS, true)) {
            $this->cache->evict(...CatalogCacheKeys::voiceTariff($operator));
        }
    }
}
