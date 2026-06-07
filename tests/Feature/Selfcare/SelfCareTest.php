<?php

namespace Tests\Feature\Selfcare;

use App\Foundation\Support\Id;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Billing\Models\Invoice;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Models\CustomerAccount;
use Modules\Subscription\Models\Subscription;
use Tests\TestCase;

class SelfCareTest extends TestCase
{
    use RefreshDatabase;

    private string $customerId;

    private string $accountId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->customerId = Id::make('cust');
        Customer::query()->create([
            'customer_id' => $this->customerId, 'operator_code' => 'WIK', 'type' => 'RES',
            'name' => 'Jane Customer', 'primary_msisdn' => '+254700000001',
        ]);
        $this->accountId = Id::make('acct');
        CustomerAccount::query()->create([
            'account_id' => $this->accountId, 'customer_id' => $this->customerId, 'operator_code' => 'WIK',
            'account_number' => 'ACC-001', 'service_address' => '1 Test Road',
        ]);

        $user = User::factory()->create(['operator_code' => 'WIK', 'customer_id' => $this->customerId]);
        $user->assignRole('CUSTOMER');
        Sanctum::actingAs($user);
    }

    public function test_self_care_shows_only_my_data(): void
    {
        Subscription::query()->create([
            'subscription_id' => Id::make('sub'), 'customer_id' => $this->customerId, 'account_id' => $this->accountId,
            'operator_code' => 'WIK', 'homepass_id' => 'h1', 'package_ref' => 'pkg_fiber', 'status_code' => 'ACTIVE',
        ]);
        // A subscription belonging to someone else must not appear.
        Subscription::query()->create([
            'subscription_id' => Id::make('sub'), 'customer_id' => 'cust_other', 'account_id' => 'acct_other',
            'operator_code' => 'WIK', 'homepass_id' => 'h2', 'package_ref' => 'pkg_other', 'status_code' => 'ACTIVE',
        ]);

        $this->getJson('/api/selfcare/me')->assertOk()->assertJsonPath('customer.customer_id', $this->customerId);
        $subs = $this->getJson('/api/selfcare/subscriptions')->assertOk()->json('items');
        $this->assertCount(1, $subs);
        $this->assertSame('pkg_fiber', $subs[0]['package_ref']);
    }

    public function test_self_care_payment_settles_my_invoice(): void
    {
        Invoice::query()->create([
            'invoice_id' => Id::make('inv'), 'account_id' => $this->accountId, 'customer_id' => $this->customerId,
            'operator_code' => 'WIK', 'status' => 'OPEN', 'total_amount' => 1000, 'amount_due' => 1000,
        ]);

        $this->postJson('/api/selfcare/payments', [
            'account_id' => $this->accountId, 'paid_amount' => 1000,
        ], ['Idempotency-Key' => 'sc-pay-1'])->assertCreated();

        $this->assertSame('PAID', Invoice::query()->where('account_id', $this->accountId)->first()->status);
    }

    public function test_self_care_cannot_pay_another_customers_account(): void
    {
        $this->postJson('/api/selfcare/payments', [
            'account_id' => 'acct_someone_else', 'paid_amount' => 500,
        ], ['Idempotency-Key' => 'sc-pay-2'])->assertStatus(422)->assertJsonPath('errorCode', 'ACCOUNT_NOT_OWNED');
    }

    public function test_self_care_raise_ticket(): void
    {
        $this->postJson('/api/selfcare/tickets', [
            'subject' => 'My internet is slow', 'priority' => 'HIGH',
        ], ['Idempotency-Key' => 'sc-tkt-1'])->assertCreated();

        $items = $this->getJson('/api/selfcare/tickets')->assertOk()->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('My internet is slow', $items[0]['subject']);
    }

    public function test_staff_without_selfcare_access_is_forbidden(): void
    {
        $staff = User::factory()->create(['operator_code' => 'WIK']);
        $staff->assignRole('FIELD_TECHNICIAN');
        Sanctum::actingAs($staff);

        $this->getJson('/api/selfcare/subscriptions')->assertForbidden();
    }
}
