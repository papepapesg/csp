<?php

namespace App\Foundation\Cache;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * FOUNDATION_CACHE cache-aside helper. Redis (or any Laravel cache store) holds
 * NON-AUTHORITATIVE copies of another module's catalog/reference data — a module
 * never caches data it owns, and PostgreSQL/module APIs remain the source of truth.
 *
 * Keys follow `sophix:{sourceModule}:{aggregate}:{id}` (CACHE-KEY-1..4). Every value
 * carries a TTL (CACHE-READ-5; event-driven invalidation is primary, TTL the safety
 * net). Store failures are treated as cache MISSES — a business request never fails
 * because the cache is down (CACHE-READ-1/2); only successful source results are
 * cached (CACHE-READ-4). The store is swappable via the standard Laravel cache
 * config (array/file in dev, Redis cluster in production per the DD topology).
 */
class SophixCache
{
    /** FOUNDATION_CACHE §7 TTL defaults (seconds). */
    public const TTL_CATALOG = 86400;   // catalog reference data: 24h

    public const TTL_PRICING = 3600;    // pricing: 1h

    public const TTL_VOLATILE = 600;    // volatile lookups: 5-15min

    public function __construct(private readonly Repository $store) {}

    public static function key(string $sourceModule, string $aggregate, string $id): string
    {
        return 'sophix:'.strtolower($sourceModule).':'.strtolower($aggregate).':'.$id;
    }

    /**
     * Cache-aside read (§8): hit -> cached value; miss/error -> call the source,
     * cache a successful (non-null) result with TTL, return it either way.
     *
     * @template T
     *
     * @param  Closure():T  $source
     * @return T
     */
    public function remember(string $sourceModule, string $aggregate, string $id, int $ttlSeconds, Closure $source): mixed
    {
        $key = self::key($sourceModule, $aggregate, $id);

        try {
            $cached = $this->store->get($key);
            if ($cached !== null) {
                $this->count($sourceModule, $aggregate, 'hits');

                return $cached;
            }
        } catch (Throwable) {
            // CACHE-READ-1: treat store errors as misses; fall through to the source.
        }

        $value = $source();

        if ($value !== null) {
            try {
                $this->store->put($key, $value, $ttlSeconds);
            } catch (Throwable) {
                // CACHE-READ-2: never fail the business request because of the cache.
            }
        }
        $this->count($sourceModule, $aggregate, 'misses');

        return $value;
    }

    /** Evict one cached aggregate (lazy-evict invalidation, §9). */
    public function evict(string $sourceModule, string $aggregate, string $id): void
    {
        try {
            $this->store->forget(self::key($sourceModule, $aggregate, $id));
        } catch (Throwable) {
            // Eviction failures self-heal via TTL.
        }
    }

    /** Evict fully-qualified keys (admin invalidation endpoint, §11). */
    public function evictKeys(array $keys): int
    {
        $evicted = 0;
        foreach ($keys as $key) {
            try {
                $this->store->forget($key);
                $evicted++;
            } catch (Throwable) {
            }
        }

        return $evicted;
    }

    /** @return array{hits:int, misses:int} hit/miss counters for a key prefix (§11). */
    public function stats(string $sourceModule, string $aggregate): array
    {
        try {
            return [
                'hits' => (int) $this->store->get($this->statsKey($sourceModule, $aggregate, 'hits'), 0),
                'misses' => (int) $this->store->get($this->statsKey($sourceModule, $aggregate, 'misses'), 0),
            ];
        } catch (Throwable) {
            return ['hits' => 0, 'misses' => 0];
        }
    }

    private function count(string $sourceModule, string $aggregate, string $kind): void
    {
        try {
            $key = $this->statsKey($sourceModule, $aggregate, $kind);
            $this->store->put($key, (int) $this->store->get($key, 0) + 1, self::TTL_CATALOG);
        } catch (Throwable) {
        }
    }

    private function statsKey(string $sourceModule, string $aggregate, string $kind): string
    {
        return 'sophix:cache:stats:'.strtolower($sourceModule).':'.strtolower($aggregate).':'.$kind;
    }
}
