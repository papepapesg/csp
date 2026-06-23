<?php

namespace Modules\Ilm\Tests\Feature;

use App\Foundation\Approvals\ApprovalRequest;
use App\Foundation\Approvals\ApprovalService;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ilm\Database\Seeders\AccountFlagCatalogSeeder;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Models\CustomerAccount;
use Modules\Ilm\Models\CustomerSubStatusCatalog;
use Modules\Ilm\Services\AccountService;
use Tests\TestCase;

/**
 * ILM-CFG-01 §3.5 account flag system + sub-status registry: operator-extensible
 * flags raised per account (NPD surfaces the attention banner), and sub-status
 * transitions validated against the operator's catalog.
 */
class AccountFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountFlagCatalogSeeder::class);
        Context::setOperatorCode('WIK');
    }

    private function account(): CustomerAccount
    {
        $customerId = Id::make('cust');
        Customer::query()->create([
            'customer_id' => $customerId, 'operator_code' => 'WIK', 'type' => 'RES', 'name' => 'Test Customer',
            'primary_msisdn' => '+2547'.rand(10000000, 99999999), 'kyc_status' => 'APPROVED',
        ]);

        return CustomerAccount::query()->create([
            'account_id' => Id::make('acct'), 'operator_code' => 'WIK', 'account_number' => '002-'.rand(1000, 9999),
            'customer_id' => $customerId, 'service_address' => 'Nairobi', 'status' => 'ACTIVE', 'sub_status' => 'active',
        ]);
    }

    public function test_setting_npd_flag_raises_attention_banner(): void
    {
        $svc = app(AccountService::class);
        $account = $this->account();

        $svc->setFlag($account, 'NPD');
        $this->assertDatabaseHas('customer_account_flag', ['account_id' => $account->account_id, 'flag_code' => 'NPD', 'state' => 'ACTIVE']);
        $this->assertSame('No Payment Done (cash-only)', $account->refresh()->attention_banner); // NPD surfaces attention
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'CustomerAccountFlagSet']);
        $this->assertCount(1, $svc->activeFlags($account));
    }

    public function test_unknown_flag_is_rejected(): void
    {
        $this->expectExceptionMessage('not in the operator catalog');
        app(AccountService::class)->setFlag($this->account(), 'NOT_A_FLAG');
    }

    public function test_clearing_a_flag(): void
    {
        $svc = app(AccountService::class);
        $account = $this->account();
        $svc->setFlag($account, 'CHURN_RISK', ['score' => 80]);
        $svc->clearFlag($account, 'CHURN_RISK');

        $this->assertDatabaseHas('customer_account_flag', ['account_id' => $account->account_id, 'flag_code' => 'CHURN_RISK', 'state' => 'CLEARED']);
        $this->assertCount(0, $svc->activeFlags($account));
    }

    public function test_clearing_the_last_attention_flag_clears_the_banner(): void
    {
        $svc = app(AccountService::class);
        $account = $this->account();

        // NPD surfaces attention -> banner is set.
        $svc->setFlag($account, 'NPD');
        $this->assertSame('No Payment Done (cash-only)', $account->refresh()->attention_banner);

        // Clearing the only attention-surfacing flag must clear the banner (was left stale before).
        $svc->clearFlag($account, 'NPD');
        $this->assertNull($account->refresh()->attention_banner);
    }

    public function test_sub_status_must_be_in_the_registry(): void
    {
        $svc = app(AccountService::class);
        $account = $this->account();

        $this->expectExceptionMessage("not in the operator's registry");
        $svc->update($account, ['sub_status' => 'made_up_status']);
    }

    public function test_valid_sub_status_transition_is_accepted(): void
    {
        $svc = app(AccountService::class);
        $account = $this->account();
        $svc->update($account, ['sub_status' => 'seasonal_disconnect']);
        // Main status is DERIVED from the catalog (seasonal_disconnect clones from ACTIVE).
        $this->assertSame('seasonal_disconnect', $account->refresh()->sub_status);
        $this->assertSame('ACTIVE', $account->status);
    }

    public function test_sub_status_requiring_approval_routes_through_em_cfg_04(): void
    {
        $this->seed(RbacSeeder::class);
        $svc = app(AccountService::class);
        $account = $this->account();

        // 'hold' is requires_approval=true → the change is HELD and an EM-CFG-04 request is raised
        // (the seeded policy is a single supervisor stage; an operator could make it a chain).
        $svc->update($account, ['sub_status' => 'hold']);
        $this->assertSame('active', $account->refresh()->sub_status); // unchanged — pending approval

        $req = ApprovalRequest::query()
            ->where('entity_type', 'CUSTOMER_SUB_STATUS')->where('entity_ref', $account->account_id)->firstOrFail();
        $this->assertSame('PENDING', $req->status);
        $this->assertSame('hold', $req->action);

        // A CUSTOMER_CARE_SUPERVISOR approves → the held transition applies (main status derived INACTIVE).
        $sup = User::factory()->create(['operator_code' => 'WIK']);
        $sup->assignRole('CUSTOMER_CARE_SUPERVISOR');
        app(ApprovalService::class)->decide($req, true, $sup);
        $this->artisan('sophix:outbox:dispatch')->assertSuccessful();

        $this->assertSame('hold', $account->refresh()->sub_status);
        $this->assertSame('INACTIVE', $account->status);
        $this->assertDatabaseHas('account_status_history', ['account_id' => $account->account_id, 'new_sub_status' => 'hold', 'approval_reference' => $req->request_id]);
    }

    public function test_approval_requirement_is_config_not_code(): void
    {
        $svc = app(AccountService::class);
        $account = $this->account();

        // An operator drops the approval requirement on 'hold' by editing the catalog row — no code change.
        CustomerSubStatusCatalog::query()
            ->where('operator_code', 'WIK')->where('sub_status_code', 'hold')->update(['requires_approval' => false]);

        $svc->update($account, ['sub_status' => 'hold']); // now accepted without a reference
        $this->assertSame('hold', $account->refresh()->sub_status);
    }
}
