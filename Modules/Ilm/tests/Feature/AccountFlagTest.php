<?php

namespace Modules\Ilm\Tests\Feature;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ilm\Database\Seeders\AccountFlagCatalogSeeder;
use Modules\Ilm\Models\CustomerAccount;
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
        \Modules\Ilm\Models\Customer::query()->create([
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

    public function test_sub_status_requiring_approval_needs_a_reference(): void
    {
        $svc = app(AccountService::class);
        $account = $this->account();

        // 'hold' is configured requires_approval=true — rejected without a reference (R-ILM-S-2).
        try {
            $svc->update($account, ['sub_status' => 'hold']);
            $this->fail('expected SUB_STATUS_APPROVAL_REQUIRED');
        } catch (\App\Foundation\Errors\DomainException $e) {
            $this->assertSame('SUB_STATUS_APPROVAL_REQUIRED', $e->errorCode);
        }

        // With a reference it is accepted and the main status is derived as INACTIVE.
        $svc->update($account, ['sub_status' => 'hold', 'approval_reference' => 'TKT-2026-01']);
        $this->assertSame('hold', $account->refresh()->sub_status);
        $this->assertSame('INACTIVE', $account->status);
        $this->assertDatabaseHas('account_status_history', ['account_id' => $account->account_id, 'new_sub_status' => 'hold', 'approval_reference' => 'TKT-2026-01']);
    }

    public function test_approval_requirement_is_config_not_code(): void
    {
        $svc = app(AccountService::class);
        $account = $this->account();

        // An operator drops the approval requirement on 'hold' by editing the catalog row — no code change.
        \Modules\Ilm\Models\CustomerSubStatusCatalog::query()
            ->where('operator_code', 'WIK')->where('sub_status_code', 'hold')->update(['requires_approval' => false]);

        $svc->update($account, ['sub_status' => 'hold']); // now accepted without a reference
        $this->assertSame('hold', $account->refresh()->sub_status);
    }
}
