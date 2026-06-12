<?php

namespace Modules\Catalog\Support;

use App\Foundation\Cache\SophixCache;

/**
 * Single source of truth for the catalog read-model cache keys, shared by the
 * cache-aside readers (SIP-02 available packages, PLM-CFG-07 voice rating) and
 * the event-driven CatalogCacheInvalidator. Keys are coarse, per-operator
 * snapshots so the array/Redis store can be evicted by exact key (no scans):
 * a lifecycle event evicts the whole operator snapshot, the next read rebuilds.
 */
final class CatalogCacheKeys
{
    /** SIP-02 sellable-package read model (R-SIP-02-12: refreshed by lifecycle events). */
    public const AVAILABLE_MODULE = 'catalog';

    public const AVAILABLE_AGG = 'available-packages';

    /** PLM-CFG-07 active voice-tariff snapshot used by the rating lookup. */
    public const VOICE_MODULE = 'plm';

    public const VOICE_AGG = 'voice-tariff';

    /** @return array{0:string,1:string,2:string} [module, aggregate, id] for SophixCache. */
    public static function availablePackages(string $operatorCode): array
    {
        return [self::AVAILABLE_MODULE, self::AVAILABLE_AGG, $operatorCode];
    }

    /** @return array{0:string,1:string,2:string} */
    public static function voiceTariff(string $operatorCode): array
    {
        return [self::VOICE_MODULE, self::VOICE_AGG, $operatorCode];
    }

    public static function availablePackagesTtl(): int
    {
        return SophixCache::TTL_PRICING;
    }

    public static function voiceTariffTtl(): int
    {
        return SophixCache::TTL_CATALOG;
    }
}
