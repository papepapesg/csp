<?php

namespace Modules\Billing\Wallet\Listeners;

use App\Foundation\Cache\SophixCache;
use App\Foundation\Events\OutboxEventPublished;

/**
 * FOUNDATION_CACHE §9 event-driven synchronization. WalletService keeps the hot
 * per-charge wallet-types lookups cache-aside; when a catalog row is created/
 * updated/activated/retired the cached copies are evicted (lazy evict; the next
 * read refills from the source of truth). Retire/delete events MUST evict.
 */
class EvictPlmCatalogCache
{
    private const WALLET_EVENTS = ['WalletCreated', 'WalletUpdated', 'WalletActivated', 'WalletRetired'];

    public function __construct(private readonly SophixCache $cache) {}

    public function handle(OutboxEventPublished $published): void
    {
        $event = $published->event;
        if (! in_array($event->event_type, self::WALLET_EVENTS, true)) {
            return;
        }

        $code = $event->payload['code'] ?? null;
        $operator = $event->operator_code;
        if ($operator && $code) {
            $this->cache->evict('plm', 'wallet', "{$operator}:{$code}");
        }
        if ($operator) {
            $this->cache->evict('plm', 'wallet-types', $operator);
        }
    }
}
