<?php

namespace Modules\Ilm\Tests\Feature;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ilm\Database\Seeders\AccountFlagCatalogSeeder;
use Modules\Ilm\Models\CustomerAccount;
use Modules\Ilm\Services\AccountService;
use Modules\Ilm\Cvm\Services\CvmFlagEvaluatorService;
use Modules\Rules\Models\DecisionTable;
use Tests\TestCase;

/** EM-03 / CVM: account_status_history + the daily rule-driven flag evaluator. */
class CvmFlagEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Context::setOperatorCode('WIK');
        $this->seed(AccountFlagCatalogSeeder::class);
    }

    private function account(string $status = 'ACTIVE', string $subStatus = 'NEW'): CustomerAccount
    {
        \Modules\Ilm\Models\Customer::query()->firstOrCreate(['customer_id' => 'c1'], [
            'operator_code' => 'WIK', 'type' => 'RES', 'name' => 'X', 'primary_msisdn' => '+254700000001',
        ]);

        return CustomerAccount::query()->create([
            'account_id' => Id::make('acct'), 'account_number' => 'A'.Id::make('n'), 'customer_id' => 'c1',
            'operator_code' => 'WIK', 'service_address' => 'x', 'status' => $status, 'sub_status' => $subStatus,
        ]);
    }

    public function test_status_change_is_recorded_in_history(): void
    {
        $account = $this->account('INACTIVE', 'NEW');
        app(AccountService::class)->update($account, ['status' => 'ACTIVE']);

        $this->assertDatabaseHas('account_status_history', [
            'account_id' => $account->account_id, 'prev_status' => 'INACTIVE', 'new_status' => 'ACTIVE',
        ]);
    }

    public function test_flag_evaluator_raises_the_rule_decided_flag(): void
    {
        // Operator policy: suspended accounts get a CHURN_RISK flag.
        DecisionTable::query()->create([
            'table_id' => Id::make('dt'), 'rule_set' => 'rules.cvm.flag-evaluation', 'operator_code' => 'WIK', 'version' => 1,
            'name' => 'CVM flags', 'hit_policy' => 'FIRST',
            'rules' => [['ruleId' => 'R-CVM-1', 'when' => [['var' => 'status', 'op' => 'eq', 'value' => 'SUSPENDED']], 'then' => ['flag' => 'CHURN_RISK']]],
            'default_output' => [], 'status' => 'DEPLOYED',
        ]);
        $suspended = $this->account('SUSPENDED');
        $this->account('ACTIVE'); // unaffected

        $r = app(CvmFlagEvaluatorService::class)->run('WIK');
        $this->assertSame(2, $r['scanned']);
        $this->assertSame(1, $r['flagged']);
        $this->assertDatabaseHas('customer_account_flag', ['account_id' => $suspended->account_id, 'flag_code' => 'CHURN_RISK', 'state' => 'ACTIVE']);
    }
}
