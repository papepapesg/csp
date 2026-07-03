<?php

namespace Modules\Billing\Wallet\Tests\Feature;

use App\Foundation\Support\Id;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Billing\Wallet\Database\Seeders\WalletCatalogSeeder;
use Modules\Catalog\Plm\Models\Service;
use Modules\Catalog\Plm\Models\ServiceClass;
use Modules\Billing\Wallet\Models\WalletCatalog;
use Tests\TestCase;

/**
 * PLM-CFG-03 wallet catalog: CRUD + DRAFT→ACTIVE→RETIRED lifecycle and the R-W
 * validation rules that govern what wallets exist and how they behave.
 */
class WalletCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(WalletCatalogSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    public function test_seed_provides_money_and_voice_wallets(): void
    {
        $this->assertDatabaseHas('wallet_catalog', ['code' => 'MONEY_KES', 'applicability' => 'PREPAID_ONLY', 'charging_precedence' => 100, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('wallet_catalog', ['code' => 'VOICE_KES', 'charging_precedence' => 90, 'status' => 'ACTIVE']);
        // List is ordered by precedence.
        $this->getJson('/api/wallet-catalog')->assertOk();
    }

    public function test_create_then_activate_a_wallet(): void
    {
        $res = $this->postJson('/api/wallet-catalog', [
            'code' => 'BONUS2_KES', 'description' => 'Second bonus wallet', 'wallet_type_code' => 'BONUS',
            'currency' => 'KES', 'applicability' => 'PREPAID_ONLY', 'charging_precedence' => 70, 'refillable' => false,
        ], ['Idempotency-Key' => 'w-create'])->assertCreated()->assertJsonPath('status', 'DRAFT');

        $id = $res->json('wallet_catalog_id');
        $this->postJson("/api/wallet-catalog/{$id}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE');
    }

    public function test_duplicate_code_is_rejected(): void
    {
        $this->postJson('/api/wallet-catalog', [
            'code' => 'MONEY_KES', 'description' => 'dupe', 'wallet_type_code' => 'MONEY', 'currency' => 'KES',
        ], ['Idempotency-Key' => 'w-dupe'])->assertStatus(422)->assertJsonPath('errorCode', 'R-PLM-CFG-03-W-1');
    }

    public function test_expiry_requires_period_days(): void
    {
        $this->postJson('/api/wallet-catalog', [
            'code' => 'EXP_KES', 'description' => 'expiring', 'wallet_type_code' => 'PROMO', 'currency' => 'KES',
            'expires' => true, // no expiry_period_days
        ], ['Idempotency-Key' => 'w-exp'])->assertStatus(422)->assertJsonPath('errorCode', 'R-PLM-CFG-03-W-9');
    }

    public function test_points_wallet_requires_conversion_rate(): void
    {
        $this->postJson('/api/wallet-catalog', [
            'code' => 'POINTS2', 'description' => 'points', 'wallet_type_code' => 'LOYALTY_POINTS', 'currency' => 'KES',
            'decimal_precision' => 0, // points unit, but no points_to_currency_rate
        ], ['Idempotency-Key' => 'w-pts'])->assertStatus(422)->assertJsonPath('errorCode', 'R-PLM-CFG-03-W-15');
    }

    public function test_retire_is_blocked_while_a_service_references_the_wallet(): void
    {
        // A Service points its default_wallet_ref at MONEY_KES.
        $sc = ServiceClass::query()->create(['id' => Id::make('scls'), 'operator_code' => 'WIK', 'name' => 'Internet']);
        Service::query()->create([
            'id' => Id::make('svc'), 'operator_code' => 'WIK', 'name' => 'Fiber 100M', 'code' => 'NET100',
            'service_class_id' => $sc->id, 'default_wallet_ref' => 'MONEY_KES', 'status' => 'ACTIVE',
        ]);

        $wallet = WalletCatalog::query()->where('operator_code', 'WIK')->where('code', 'MONEY_KES')->first();
        $this->postJson("/api/wallet-catalog/{$wallet->wallet_catalog_id}/retire")
            ->assertStatus(409)->assertJsonPath('errorCode', 'CONFLICT');

        // An unreferenced wallet retires cleanly.
        $free = WalletCatalog::query()->where('operator_code', 'WIK')->where('code', 'DEPOSIT_KES')->first();
        $this->postJson("/api/wallet-catalog/{$free->wallet_catalog_id}/retire")->assertOk()->assertJsonPath('status', 'RETIRED');
    }
}
