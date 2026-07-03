<?php

namespace Modules\Billing\Wallet\Tests\Feature;

use App\Foundation\Support\Id;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Billing\Wallet\Database\Seeders\WalletTypeSeeder;
use Modules\Catalog\Plm\Models\Service;
use Modules\Catalog\Plm\Models\ServiceClass;
use Modules\Billing\Wallet\Models\WalletType;
use Tests\TestCase;

/**
 * PLM-CFG-03 wallet types — THE single wallet catalog: CRUD + DRAFT→ACTIVE→
 * RETIRED lifecycle and the R-W validation rules. Deliberately currency-free:
 * the deployment currency lives on operator_config, so a catalog row can never
 * introduce a second currency into a deployment.
 */
class WalletTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(WalletTypeSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    public function test_seed_provides_money_and_voice_wallets(): void
    {
        $this->assertDatabaseHas('wallet_type', ['code' => 'MONEY', 'role' => 'SETTLEMENT', 'charging_precedence' => 100, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('wallet_type', ['code' => 'VOICE', 'role' => 'SETTLEMENT', 'charging_precedence' => 90, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('wallet_type', ['code' => 'DEPOSIT', 'role' => 'DEPOSIT', 'status' => 'ACTIVE']);
        // List is ordered by precedence.
        $this->getJson('/api/wallet-types')->assertOk();
    }

    /**
     * EXPECTATION — authoring is currency-free: a new type declares its unit and
     * behaviour, is born DRAFT, and only serves charging once activated.
     */
    public function test_create_then_activate_a_wallet(): void
    {
        $res = $this->postJson('/api/wallet-types', [
            'code' => 'BONUS2', 'description' => 'Second bonus wallet', 'role' => 'SETTLEMENT', 'unit' => 'currency',
            'charging_precedence' => 70, 'refillable' => false,
        ], ['Idempotency-Key' => 'w-create'])->assertCreated()->assertJsonPath('status', 'DRAFT');

        $id = $res->json('wallet_type_id');
        $this->postJson("/api/wallet-types/{$id}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE');
    }

    public function test_duplicate_code_is_rejected(): void
    {
        $this->postJson('/api/wallet-types', [
            'code' => 'MONEY', 'description' => 'dupe',
        ], ['Idempotency-Key' => 'w-dupe'])->assertStatus(422)->assertJsonPath('errorCode', 'R-PLM-CFG-03-W-1');
    }

    public function test_expiry_requires_period_days(): void
    {
        $this->postJson('/api/wallet-types', [
            'code' => 'EXPIRING', 'description' => 'expiring',
            'expires' => true, // no expiry_period_days
        ], ['Idempotency-Key' => 'w-exp'])->assertStatus(422)->assertJsonPath('errorCode', 'R-PLM-CFG-03-W-9');
    }

    public function test_points_wallet_requires_conversion_rate(): void
    {
        $this->postJson('/api/wallet-types', [
            'code' => 'POINTS2', 'description' => 'points', 'unit' => 'points',
            'decimal_precision' => 0, // points unit, but no points_to_currency_rate
        ], ['Idempotency-Key' => 'w-pts'])->assertStatus(422)->assertJsonPath('errorCode', 'R-PLM-CFG-03-W-15');
    }

    public function test_retire_is_blocked_while_a_service_references_the_wallet(): void
    {
        // A Service points its default_wallet_ref at MONEY.
        $sc = ServiceClass::query()->create(['id' => Id::make('scls'), 'operator_code' => 'WIK', 'name' => 'Internet']);
        Service::query()->create([
            'id' => Id::make('svc'), 'operator_code' => 'WIK', 'name' => 'Fiber 100M', 'code' => 'NET100',
            'service_class_id' => $sc->id, 'default_wallet_ref' => 'MONEY', 'status' => 'ACTIVE',
        ]);

        $wallet = WalletType::query()->where('operator_code', 'WIK')->where('code', 'MONEY')->first();
        $this->postJson("/api/wallet-types/{$wallet->wallet_type_id}/retire")
            ->assertStatus(409)->assertJsonPath('errorCode', 'CONFLICT');

        // An unreferenced wallet retires cleanly.
        $free = WalletType::query()->where('operator_code', 'WIK')->where('code', 'DEPOSIT')->first();
        $this->postJson("/api/wallet-types/{$free->wallet_type_id}/retire")->assertOk()->assertJsonPath('status', 'RETIRED');
    }
}
