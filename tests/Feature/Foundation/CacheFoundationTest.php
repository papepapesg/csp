<?php

namespace Tests\Feature\Foundation;

use App\Foundation\Cache\SophixCache;
use App\Foundation\Support\Context;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Billing\Wallet\Services\WalletService;
use Modules\Catalog\Database\Seeders\WalletCatalogSeeder;
use Modules\Catalog\Models\WalletCatalog;
use Modules\Catalog\Services\WalletCatalogService;
use Tests\TestCase;

/**
 * FOUNDATION_CACHE: cache-aside reads of another module's catalog (Billing <- PLM
 * wallet catalog), event-driven invalidation (Wallet* events evict), failure
 * fallback (store errors are misses, never business failures), and the §11 admin
 * invalidate/stats endpoints.
 */
class CacheFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(WalletCatalogSeeder::class);
        Context::setOperatorCode('WIK');
    }

    public function test_wallet_catalog_read_is_cache_aside(): void
    {
        $wallets = app(WalletService::class);

        // First read caches the catalog entry (sophix:plm:wallet:WIK:MONEY_KES).
        $a = $wallets->ensureWallet('sub_cache_a', 'MONEY_KES');
        $this->assertSame('KES', $a->currency);

        // Mutate the source directly (bypassing events): cached copy still serves.
        DB::table('wallet_catalog')->where('code', 'MONEY_KES')->update(['currency' => 'USD']);
        $b = $wallets->ensureWallet('sub_cache_b', 'MONEY_KES');
        $this->assertSame('KES', $b->currency); // served from cache — TTL is the safety net

        // Evict (admin/lazy-evict path) -> next read refills from the source of truth.
        app(SophixCache::class)->evict('plm', 'wallet', 'WIK:MONEY_KES');
        $c = $wallets->ensureWallet('sub_cache_c', 'MONEY_KES');
        $this->assertSame('USD', $c->currency);
    }

    public function test_wallet_event_evicts_the_consuming_modules_cache(): void
    {
        $wallets = app(WalletService::class);
        $svc = app(WalletCatalogService::class);

        // Prime the cached catalog set: VOICE(90) drains before MONEY(100).
        $wallets->ensureWallet('sub_evt', 'MONEY_KES');
        $wallets->ensureWallet('sub_evt', 'VOICE_KES');
        $this->assertSame(['VOICE_KES', 'MONEY_KES'],
            $wallets->resolveChargingWallets('sub_evt', 'PREPAID')->pluck('wallet_code')->all());

        // PLM reorders precedence (MONEY now first). Before the event is dispatched,
        // the consumer still serves its cached copy…
        $money = WalletCatalog::query()->where('operator_code', 'WIK')->where('code', 'MONEY_KES')->firstOrFail();
        $svc->update($money, ['charging_precedence' => 10]);
        $this->assertSame(['VOICE_KES', 'MONEY_KES'],
            $wallets->resolveChargingWallets('sub_evt', 'PREPAID')->pluck('wallet_code')->all());

        // …until WalletUpdated flows through the outbox and evicts (§9 lazy evict).
        Artisan::call('sophix:outbox:dispatch');
        $this->assertSame(['MONEY_KES', 'VOICE_KES'],
            $wallets->resolveChargingWallets('sub_evt', 'PREPAID')->pluck('wallet_code')->all());
    }

    public function test_store_failure_is_a_miss_not_a_business_failure(): void
    {
        // CACHE-READ-1/2: a broken store must not break the read.
        $broken = \Mockery::mock(Repository::class);
        $broken->shouldReceive('get')->andThrow(new \RuntimeException('redis down'));
        $broken->shouldReceive('put')->andThrow(new \RuntimeException('redis down'));
        $cache = new SophixCache($broken);

        $value = $cache->remember('plm', 'wallet', 'X', 60, fn () => 'from-source');
        $this->assertSame('from-source', $value);
    }

    public function test_admin_invalidate_and_stats_endpoints(): void
    {
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);

        // Prime a key + counters.
        app(WalletService::class)->ensureWallet('sub_admin', 'MONEY_KES');

        $stats = $this->getJson('/api/admin/cache/stats?module=plm&aggregate=wallet')->assertOk()->json();
        $this->assertGreaterThanOrEqual(1, $stats['misses']);

        $this->postJson('/api/admin/cache/invalidate', ['module' => 'plm', 'aggregate' => 'wallet', 'ids' => ['WIK:MONEY_KES']])
            ->assertOk()->assertJsonPath('invalidated', 1);

        // After invalidation the next read goes back to the source.
        DB::table('wallet_catalog')->where('code', 'MONEY_KES')->update(['currency' => 'TZS']);
        $this->assertSame('TZS', app(WalletService::class)->ensureWallet('sub_admin2', 'MONEY_KES')->currency);
    }
}
