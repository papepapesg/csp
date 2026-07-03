<?php

namespace Modules\Billing\Wallet\Tests\Feature;

use App\Foundation\Support\Context;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Billing\Wallet\Database\Seeders\WalletTypeSeeder;
use Modules\Billing\Wallet\Services\WalletService;
use Tests\TestCase;

/**
 * PLM-CFG-03 allowance wallets (R-W-16) — in-kind bundles (DATA/SMS/VOICE units)
 * a subscription holds and consumes by usage, distinct from money settlement.
 * This covers the wallet-side capability: authoring an ALLOWANCE type, granting
 * units, and consuming them in-kind. The end-to-end "usage burns the allowance,
 * overage bills" path is in MediationAllowanceTest.
 */
class WalletAllowanceTest extends TestCase
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
        Context::setOperatorCode('WIK');
    }

    /**
     * EXPECTATION — an allowance type is authored as a MEASURE with coverage.
     * An ALLOWANCE wallet counts a usage measure (not money/points) and must say
     * which CDR usage types it burns for. A money unit, or missing coverage, is
     * rejected (R-W-16); a well-formed data bundle is accepted.
     */
    public function test_allowance_authoring_requires_measure_and_coverage(): void
    {
        // Money unit on an allowance → rejected.
        $this->postJson('/api/wallet-types', [
            'code' => 'BAD1', 'description' => 'x', 'role' => 'ALLOWANCE', 'unit' => 'currency',
            'covered_usage_types' => ['DATA'],
        ], ['Idempotency-Key' => 'al-1'])->assertStatus(422)->assertJsonPath('errorCode', 'R-PLM-CFG-03-W-16');

        // Allowance with no coverage → rejected.
        $this->postJson('/api/wallet-types', [
            'code' => 'BAD2', 'description' => 'x', 'role' => 'ALLOWANCE', 'unit' => 'DATA',
        ], ['Idempotency-Key' => 'al-2'])->assertStatus(422)->assertJsonPath('errorCode', 'R-PLM-CFG-03-W-16');

        // coverage on a non-allowance → rejected.
        $this->postJson('/api/wallet-types', [
            'code' => 'BAD3', 'description' => 'x', 'role' => 'SETTLEMENT', 'unit' => 'currency',
            'covered_usage_types' => ['DATA'],
        ], ['Idempotency-Key' => 'al-3'])->assertStatus(422)->assertJsonPath('errorCode', 'R-PLM-CFG-03-W-16');

        // A well-formed SMS bundle is accepted.
        $this->postJson('/api/wallet-types', [
            'code' => 'SMS_BUNDLE', 'description' => 'Included SMS', 'role' => 'ALLOWANCE', 'unit' => 'SMS',
            'covered_usage_types' => ['SMS'], 'expires' => true, 'expiry_period_days' => 30, 'decimal_precision' => 0,
        ], ['Idempotency-Key' => 'al-ok'])->assertCreated()->assertJsonPath('status', 'DRAFT');
    }

    /**
     * EXPECTATION — allowances are granted and consumed in their own unit.
     * A 5,000-unit DATA bundle grant is held in-kind; consuming 3,000 leaves
     * 2,000, recorded as an ALLOWANCE_USE ledger row (not a money movement).
     * An allowance grant is NOT a top-up — it emits WalletCredited, not
     * WalletToppedUp, so it never settles a pending money intent.
     */
    public function test_grant_then_consume_allowance_in_kind(): void
    {
        $wallets = app(WalletService::class);

        $wallets->grantAllowance('sub_al', 'DATA_BUNDLE', 5000);
        $this->assertEqualsWithDelta(5000.0, $wallets->allowanceBalanceFor('sub_al', 'DATA'), 0.001);

        $consumed = $wallets->consumeAllowance('sub_al', 'DATA', 3000);
        $this->assertEqualsWithDelta(3000.0, $consumed, 0.001);
        $this->assertEqualsWithDelta(2000.0, $wallets->allowanceBalanceFor('sub_al', 'DATA'), 0.001);

        $this->assertDatabaseHas('wallet_transaction', ['reason' => 'ALLOWANCE_USE', 'direction' => 'DEBIT', 'amount' => 3000.00]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'WalletCredited']);   // the grant
        $this->assertDatabaseMissing('outbox_events', ['event_type' => 'WalletToppedUp']); // never a top-up
    }

    /**
     * EXPECTATION — consumption never over-draws: it caps at what's available and
     * a usage type with no covering bundle consumes nothing.
     */
    public function test_consume_caps_at_available_and_ignores_uncovered_usage(): void
    {
        $wallets = app(WalletService::class);
        $wallets->grantAllowance('sub_cap', 'DATA_BUNDLE', 800);

        // Asking for 2000 only takes the 800 that exist.
        $this->assertEqualsWithDelta(800.0, $wallets->consumeAllowance('sub_cap', 'DATA', 2000), 0.001);
        $this->assertEqualsWithDelta(0.0, $wallets->allowanceBalanceFor('sub_cap', 'DATA'), 0.001);

        // SMS has no covering bundle on this subscription → nothing to consume.
        $this->assertEqualsWithDelta(0.0, $wallets->allowanceBalanceFor('sub_cap', 'SMS'), 0.001);
        $this->assertEqualsWithDelta(0.0, $wallets->consumeAllowance('sub_cap', 'SMS', 50), 0.001);
    }
}
