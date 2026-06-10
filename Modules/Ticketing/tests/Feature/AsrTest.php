<?php

namespace Modules\Ticketing\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Ticketing\Database\Seeders\AsrPolicySeeder;
use Modules\Ticketing\Database\Seeders\SlaPolicySeeder;
use Tests\TestCase;

class AsrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(\Modules\Ticketing\Database\Seeders\TicketCategorySeeder::class);
        $this->seed(SlaPolicySeeder::class);
        $this->seed(AsrPolicySeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    public function test_technical_trouble_routes_to_noc_and_raises_work_order(): void
    {
        $res = $this->postJson('/api/asr', [
            'asr_type' => 'TECHNICAL_TROUBLE', 'subject' => 'No internet', 'customer_id' => 'cust_1', 'subscription_id' => 'sub_1',
        ], ['Idempotency-Key' => 'asr-1'])->assertCreated();
        $res->assertJsonPath('asr_type', 'TECHNICAL_TROUBLE')->assertJsonPath('queue', 'NOC')->assertJsonPath('priority', 'HIGH');

        // Auto-created a field work order.
        $this->assertDatabaseHas('work_order', ['type' => 'SUPPORT']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'WorkOrderCreated']);
    }

    public function test_service_request_routes_to_fulfillment(): void
    {
        $this->postJson('/api/asr', ['asr_type' => 'SERVICE_REQUEST', 'subject' => 'Upgrade my plan'], ['Idempotency-Key' => 'asr-2'])
            ->assertCreated()->assertJsonPath('queue', 'FULFILLMENT')->assertJsonPath('category', 'SERVICE_REQUEST');
    }

    public function test_routing_can_branch_on_vip_when_an_operator_configures_it(): void
    {
        // An operator adds a VIP rule on top — no code change, just data. The full
        // context (incl. the vip account flag) is fed to the rule engine.
        \Modules\Rules\Models\DecisionTable::query()->where('rule_set', 'rules.asr.routing')->update([
            'rules' => [
                ['ruleId' => 'R-VIP', 'when' => [['var' => 'vip', 'op' => 'eq', 'value' => true]], 'then' => ['queue' => 'VIP_DESK', 'priority' => 'URGENT']],
                ['ruleId' => 'R-COMPLAINT', 'when' => [['var' => 'asrType', 'op' => 'eq', 'value' => 'COMPLAINT']], 'then' => ['queue' => 'QUALITY', 'priority' => 'HIGH']],
            ],
        ]);

        $cid = \App\Foundation\Support\Id::make('cust');
        \Modules\Ilm\Models\Customer::query()->create(['customer_id' => $cid, 'operator_code' => 'WIK', 'type' => 'RES', 'name' => 'V', 'primary_msisdn' => '+254700555000']);
        // VIP is the account's sub-status (ILM-CFG-01 §3.5), fed to routing as vip == true.
        \Modules\Ilm\Models\CustomerAccount::query()->create(['account_id' => 'acct_vip', 'customer_id' => $cid, 'operator_code' => 'WIK', 'account_number' => 'A-VIP', 'service_address' => 'x', 'status' => 'ACTIVE', 'sub_status' => 'vip']);

        // A complaint from a VIP account is pulled to the VIP desk, not QUALITY.
        $this->postJson('/api/asr', ['asr_type' => 'COMPLAINT', 'subject' => 'unhappy', 'customer_id' => $cid, 'account_id' => 'acct_vip'], ['Idempotency-Key' => 'asr-vip'])
            ->assertCreated()->assertJsonPath('queue', 'VIP_DESK')->assertJsonPath('priority', 'URGENT');

        // A non-VIP complaint still routes by type.
        $this->postJson('/api/asr', ['asr_type' => 'COMPLAINT', 'subject' => 'also unhappy'], ['Idempotency-Key' => 'asr-novip'])
            ->assertCreated()->assertJsonPath('queue', 'QUALITY');
    }
}
