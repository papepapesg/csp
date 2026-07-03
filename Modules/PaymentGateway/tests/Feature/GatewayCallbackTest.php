<?php

namespace Modules\PaymentGateway\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Billing\Invoicing\Models\Invoice;
use Modules\Billing\Payments\Models\PaymentLedger;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Models\CustomerAccount;
use Tests\TestCase;

class GatewayCallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    private function makeAccountWithInvoice(): CustomerAccount
    {
        $customer = Customer::factory()->create();
        $account = CustomerAccount::query()->create([
            'customer_id' => $customer->customer_id,
            'service_address' => 'X',
            'payment_account_number' => 'PB-12345',
        ]);
        Invoice::query()->create([
            'operator_code' => 'WIK', 'account_id' => $account->account_id, 'currency' => 'KES',
            'status' => Invoice::OPEN, 'issue_date' => now(), 'due_date' => now()->addDays(7),
            'subtotal_amount' => 1000, 'total_amount' => 1000, 'amount_due' => 1000,
        ]);

        return $account;
    }

    public function test_mpesa_callback_resolves_account_and_applies_payment(): void
    {
        $account = $this->makeAccountWithInvoice();

        $this->postJson('/api/payment-gateway/mpesa/callbacks', [
            'external_ref' => 'MPESA-TXN-001',
            'account_ref' => 'PB-12345',
            'amount' => 1000,
        ])->assertStatus(202)->assertJsonPath('status', 'PROCESSED');

        $this->assertDatabaseHas('payment_ledger', ['account_id' => $account->account_id, 'gateway_ref' => 'MPESA-TXN-001']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'GatewayCallbackProcessed']);
        $this->assertSame('PAID', Invoice::where('account_id', $account->account_id)->first()->status);
    }

    public function test_prepaid_callback_tops_up_the_wallet_not_an_invoice(): void
    {
        // PAY-GW-01 §3: a prepaid subscription's gateway money is a wallet top-up
        // (BIL-05), not an invoice payment.
        $this->seed(\Modules\Billing\Wallet\Database\Seeders\WalletCatalogSeeder::class);
        $customer = Customer::factory()->create();
        $account = CustomerAccount::query()->create([
            'customer_id' => $customer->customer_id, 'service_address' => 'X', 'payment_account_number' => 'PB-PREPAID',
        ]);
        $sub = \Modules\Subscription\Models\Subscription::query()->create([
            'subscription_id' => \App\Foundation\Support\Id::make('sub'), 'customer_id' => $customer->customer_id,
            'account_id' => $account->account_id, 'operator_code' => 'WIK', 'homepass_id' => 'h1',
            'package_ref' => 'pkg_1', 'status_code' => 'ACTIVE', 'billing_mode' => 'PREPAID', 'currency' => 'KES',
        ]);

        $this->postJson('/api/payment-gateway/mpesa/callbacks', [
            'external_ref' => 'MPESA-PREPAID-1', 'account_ref' => 'PB-PREPAID', 'amount' => 500,
        ])->assertStatus(202)->assertJsonPath('status', 'PROCESSED');

        // Money landed in the wallet (BIL-05). The receipt is still audited in
        // payment_ledger (status APPLIED), but NOT in account_credit_balance.
        $this->assertDatabaseHas('wallet', ['subscription_id' => $sub->subscription_id, 'wallet_code' => 'MONEY_KES', 'balance' => 500.00]);
        $this->assertDatabaseHas('payment_ledger', ['account_id' => $account->account_id, 'status' => 'APPLIED']);
        $this->assertDatabaseMissing('account_credit_balance', ['account_id' => $account->account_id]);
    }

    public function test_duplicate_callback_is_deduped(): void
    {
        $this->makeAccountWithInvoice();
        $payload = ['external_ref' => 'DUP-1', 'account_ref' => 'PB-12345', 'amount' => 100];

        $this->postJson('/api/payment-gateway/mpesa/callbacks', $payload)->assertStatus(202)->assertJsonPath('status', 'PROCESSED');
        $this->postJson('/api/payment-gateway/mpesa/callbacks', $payload)->assertStatus(202)->assertJsonPath('status', 'DUPLICATE');

        $this->assertSame(1, PaymentLedger::count());
    }

    public function test_unresolvable_account_is_rejected(): void
    {
        $this->postJson('/api/payment-gateway/mpesa/callbacks', [
            'external_ref' => 'NOACC-1', 'account_ref' => 'UNKNOWN', 'amount' => 100,
        ])->assertStatus(202)->assertJsonPath('status', 'REJECTED');

        $this->assertDatabaseHas('outbox_events', ['event_type' => 'GatewayCallbackRejected']);
    }
}
