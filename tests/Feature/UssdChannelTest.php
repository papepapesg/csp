<?php

namespace Tests\Feature;

use App\Foundation\Support\Id;
use Database\Seeders\RbacSeeder;
use Database\Seeders\UssdMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Invoice;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Models\CustomerAccount;
use Tests\TestCase;

/**
 * FE-CH-USSD-01: config-driven menu navigation, deep menu walk (support→create ticket),
 * language switching, the normalized gateway endpoint, and per-request tracing — USSD calling
 * owning module APIs without owning their logic.
 */
class UssdChannelTest extends TestCase
{
    use RefreshDatabase;

    private string $msisdn = '+254700000777';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(UssdMenuSeeder::class);

        $cid = Id::make('cust');
        Customer::query()->create(['customer_id' => $cid, 'operator_code' => 'WIK', 'type' => 'RES', 'name' => 'Ada', 'primary_msisdn' => $this->msisdn]);
        CustomerAccount::query()->create(['account_id' => 'acct_x', 'customer_id' => $cid, 'operator_code' => 'WIK', 'account_number' => 'A-X', 'service_address' => 'x']);
        Invoice::query()->create(['invoice_id' => Id::make('inv'), 'account_id' => 'acct_x', 'operator_code' => 'WIK', 'status' => 'OPEN', 'total_amount' => 1200, 'amount_due' => 1200]);
    }

    private function dial(string $text, string $session = 's1'): string
    {
        return $this->post('/api/ussd', ['sessionId' => $session, 'phoneNumber' => $this->msisdn, 'text' => $text])->assertOk()->getContent();
    }

    public function test_main_menu_is_rendered_from_config(): void
    {
        $this->assertStringContainsString('CON Welcome to SOPHIX', $this->dial(''));
        $this->assertStringContainsString('Account balance', $this->dial(''));
        // Menu came from the data, not code.
        $this->assertDatabaseHas('ussd_menu_definition', ['operator_code' => 'WIK', 'menu_code' => 'MAIN', 'language_code' => 'en']);
    }

    public function test_balance_action_shows_due(): void
    {
        $this->assertStringContainsString('END Balance due: 1,200.00 KES', $this->dial('1'));
        // Request trace recorded.
        $this->assertDatabaseHas('ussd_request_log', ['response_type' => 'END', 'menu_code' => 'BALANCE']);
    }

    public function test_pay_instructions_do_not_apply_payment(): void
    {
        $this->assertStringContainsString('Paybill', $this->dial('2'));
    }

    public function test_support_menu_walk_creates_ticket_via_tck01(): void
    {
        // 4 -> Support submenu (navigational), then 4*1 -> create ticket (action).
        $this->assertStringContainsString('CON Support', $this->dial('4'));
        $res = $this->dial('4*1');
        $this->assertStringContainsString('Ticket created', $res);
        $this->assertDatabaseHas('ticket', ['customer_id' => Customer::where('primary_msisdn', $this->msisdn)->first()->customer_id]);
    }

    public function test_unknown_msisdn_is_rejected_at_customer_gate(): void
    {
        $out = $this->post('/api/ussd', ['sessionId' => 's2', 'phoneNumber' => '+254700999999', 'text' => '1'])->assertOk()->getContent();
        $this->assertStringContainsString('No account found', $out);
    }

    public function test_language_switch_rerenders_main_in_swahili(): void
    {
        // 6 -> Language menu; 6*2 -> Kiswahili, re-render MAIN in sw.
        $this->dial('6');
        $this->assertStringContainsString('Karibu SOPHIX', $this->dial('6*2'));
        $this->assertDatabaseHas('ussd_session', ['session_id' => 's1', 'language_code' => 'sw']);
    }

    public function test_normalized_gateway_endpoint_returns_json_envelope(): void
    {
        $this->postJson('/api/channels/ussd/sessions', [
            'operatorCode' => 'WIK', 'gatewaySessionId' => 'gw-1', 'gatewayRequestId' => 'req-1', 'msisdn' => $this->msisdn, 'inputText' => '',
        ])->assertOk()->assertJsonPath('responseType', 'CON')->assertJsonPath('continue', true);

        $this->postJson('/api/channels/ussd/sessions', [
            'operatorCode' => 'WIK', 'gatewaySessionId' => 'gw-1', 'gatewayRequestId' => 'req-2', 'msisdn' => $this->msisdn, 'inputText' => '1',
        ])->assertOk()->assertJsonPath('responseType', 'END')->assertJsonFragment(['text' => "Balance due: 1,200.00 KES"]);
    }
}
