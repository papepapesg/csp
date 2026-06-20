<?php

namespace Modules\Fulfillment\Tests\Feature;

use App\Foundation\Support\Id;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Fulfillment\Database\Seeders\FulfillmentFlowSeeder;
use Modules\Fulfillment\Models\FulfillmentOrder;
use Modules\Fulfillment\Services\OrderCaptureService;
use Modules\Ilm\Database\Seeders\AccountFlagCatalogSeeder;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Models\CustomerAccount;
use Modules\Ilm\Services\AccountService;
use Modules\Ilm\Services\CustomerService;
use Modules\Rules\Database\Seeders\DecisionTableSeeder;
use Modules\Subscription\Models\Subscription;
use Modules\Workflow\Database\Seeders\ProcessDefinitionSeeder;
use Modules\WorkOrder\Models\WorkOrder;
use Modules\WorkOrder\Services\WorkOrderService;
use Tests\TestCase;

/**
 * FUL-02 order journey AS CONFIG: capture starts the ful-order-capture process;
 * workers create the subscription + install WO; the flow parks awaiting install,
 * gates on KYC, then triggers SUB-WF activation. Extending the journey = editing
 * the flow in the Workflow Studio, not this codebase.
 */
class FulfillmentJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ProcessDefinitionSeeder::class);
        $this->seed(FulfillmentFlowSeeder::class);
        $this->seed(DecisionTableSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    private function drain(): void
    {
        Artisan::call('sophix:workflow:work', ['--once' => true]);
    }

    public function test_capture_starts_the_flow_which_creates_subscription_and_install_wo(): void
    {
        $resp = $this->postJson('/api/fulfillment-orders', [
            'customer_id' => 'cust_1', 'account_id' => 'acct_1', 'homepass_id' => 'hp_1',
            'package_ref' => 'pkg_triple', 'payment_ref' => 'pay_1',
        ], ['Idempotency-Key' => 'order-1'])->assertStatus(201);

        $order = $resp->json('order');
        $this->assertSame('CAPTURED', $order['status']);
        $this->assertNotNull($order['process_instance_id']); // FUL-02-FRAMEWORK §1.1

        // The flow's workers run the journey steps up to the install wait state.
        $this->drain();
        $fresh = FulfillmentOrder::find($order['order_id']);
        $this->assertSame('AWAITING_INSTALL', $fresh->status);
        $this->assertStringStartsWith('sub_', $fresh->subscription_id);
        $this->assertStringStartsWith('wo_', $fresh->work_order_id);
        $this->assertDatabaseHas('subscription', ['subscription_id' => $fresh->subscription_id, 'status_code' => 'PENDING_ACTIVATION']);
        $this->assertDatabaseHas('work_order', ['work_order_id' => $fresh->work_order_id, 'type' => 'INSTALLATION', 'source_type' => 'FULFILLMENT']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'OrderCaptured']);
    }

    public function test_cancelling_an_order_compensates_its_install_wo_and_subscription(): void
    {
        $order = $this->postJson('/api/fulfillment-orders', [
            'customer_id' => 'cust_c', 'account_id' => 'acct_c', 'homepass_id' => 'hp_c', 'package_ref' => 'pkg_x',
        ], ['Idempotency-Key' => 'order-c'])->json('order');
        $this->drain(); // create subscription + install WO, park awaiting install

        $fresh = FulfillmentOrder::find($order['order_id']);
        $subId = $fresh->subscription_id;
        $woId = $fresh->work_order_id;
        $this->assertNotNull($subId);
        $this->assertNotNull($woId);

        // Cancel the order -> the dispatched install WO is cancelled and the half-built
        // subscription is terminated (were both left dangling before).
        $this->postJson("/api/fulfillment-orders/{$order['order_id']}/cancel", ['reason' => 'customer changed mind'])->assertOk();

        $this->assertSame('CANCELLED', FulfillmentOrder::find($order['order_id'])->status);
        $this->assertSame(WorkOrder::CANCELLED, WorkOrder::find($woId)->status);
        $this->assertSame(Subscription::TERMINATED, Subscription::find($subId)->status_code);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'OrderCancelled']);
    }

    public function test_desk_complete_resumes_the_flow_and_activates(): void
    {
        $order = $this->postJson('/api/fulfillment-orders', [
            'customer_id' => 'cust_2', 'account_id' => 'acct_2', 'homepass_id' => 'hp_2', 'package_ref' => 'pkg_x',
        ], ['Idempotency-Key' => 'order-2'])->json('order');
        $this->drain(); // park at await-install

        // Desk confirms the install -> correlates the message -> KYC gate -> activation.
        $this->postJson("/api/fulfillment-orders/{$order['order_id']}/complete", [], ['Idempotency-Key' => 'complete-2'])->assertOk();
        $this->drain();

        $fresh = FulfillmentOrder::find($order['order_id']);
        $this->assertSame('COMPLETED', $fresh->status);
        $this->assertSame('ACTIVE', Subscription::find($fresh->subscription_id)->status_code);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'OrderCompleted']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionActivated']);
    }

    public function test_install_wo_finalization_resumes_the_flow_automatically(): void
    {
        // FUL-02 wait-install-finalize: the WO event, not a desk call, resumes the journey.
        $order = $this->postJson('/api/fulfillment-orders', [
            'customer_id' => 'cust_3', 'account_id' => 'acct_3', 'homepass_id' => 'hp_3', 'package_ref' => 'pkg_y',
        ], ['Idempotency-Key' => 'order-3'])->json('order');
        $this->drain();

        $woId = FulfillmentOrder::find($order['order_id'])->work_order_id;
        $svc = app(WorkOrderService::class);
        $wo = WorkOrder::find($woId);
        $svc->assign($wo, ['contractor_id' => 'con_1']);
        $svc->start($wo->refresh());
        $svc->finalize($wo->refresh(), ['final_reason' => 'INSTALL_COMPLETED']);
        Artisan::call('sophix:outbox:dispatch'); // WorkOrderFinalized -> correlates the message
        $this->drain();

        $fresh = FulfillmentOrder::find($order['order_id']);
        $this->assertSame('COMPLETED', $fresh->status);
        $this->assertSame('ACTIVE', Subscription::find($fresh->subscription_id)->status_code);
    }

    public function test_activation_is_gated_on_customer_kyc(): void
    {
        $custId = Id::make('cust');
        Customer::query()->create([
            'customer_id' => $custId, 'operator_code' => 'WIK', 'type' => 'RES', 'name' => 'Pending KYC',
            'primary_msisdn' => '+254700111222', 'kyc_status' => 'PENDING',
        ]);

        $order = $this->postJson('/api/fulfillment-orders', [
            'customer_id' => $custId, 'account_id' => 'acct_k', 'homepass_id' => 'hp_k', 'package_ref' => 'pkg_x',
        ], ['Idempotency-Key' => 'order-k'])->json('order');
        $this->drain();

        // Install confirmed, but the KYC gate parks the order — no activation yet.
        $this->postJson("/api/fulfillment-orders/{$order['order_id']}/complete", [], ['Idempotency-Key' => 'complete-k'])->assertOk();
        $this->drain();
        $fresh = FulfillmentOrder::find($order['order_id']);
        $this->assertSame('AWAITING_KYC', $fresh->status);
        $this->assertSame('PENDING_ACTIVATION', Subscription::find($fresh->subscription_id)->status_code);

        // Final KYC approval emits CustomerKycApproved -> listener correlates -> flow resumes.
        $customers = app(CustomerService::class);
        $customer = Customer::find($custId);
        $customers->recordKycDecision($customer, 1, 'APPROVED');
        $customers->recordKycDecision($customer->refresh(), 2, 'APPROVED');
        Artisan::call('sophix:outbox:dispatch');
        $this->drain();

        $fresh = FulfillmentOrder::find($order['order_id']);
        $this->assertSame('COMPLETED', $fresh->status);
        $this->assertSame('ACTIVE', Subscription::find($fresh->subscription_id)->status_code);
        $this->assertDatabaseHas('fulfillment_order_step', ['order_id' => $order['order_id'], 'step' => 'KYC']);
    }

    public function test_kyc_rejection_cancels_the_parked_order_and_compensates(): void
    {
        $custId = Id::make('cust');
        Customer::query()->create([
            'customer_id' => $custId, 'operator_code' => 'WIK', 'type' => 'RES', 'name' => 'Rejected KYC',
            'primary_msisdn' => '+254700333444', 'kyc_status' => 'PENDING',
        ]);

        $order = $this->postJson('/api/fulfillment-orders', [
            'customer_id' => $custId, 'account_id' => 'acct_r', 'homepass_id' => 'hp_r', 'package_ref' => 'pkg_x',
        ], ['Idempotency-Key' => 'order-r'])->json('order');
        $this->drain();
        $this->postJson("/api/fulfillment-orders/{$order['order_id']}/complete", [], ['Idempotency-Key' => 'complete-r'])->assertOk();
        $this->drain();

        $fresh = FulfillmentOrder::find($order['order_id']);
        $this->assertSame('AWAITING_KYC', $fresh->status);
        $woId = $fresh->work_order_id;

        // Final KYC rejection -> CustomerKycRejected -> the parked order is cancelled (was stuck forever).
        $customers = app(CustomerService::class);
        $customer = Customer::find($custId);
        $customers->recordKycDecision($customer, 1, 'REJECTED');
        Artisan::call('sophix:outbox:dispatch');

        $this->assertSame('CANCELLED', FulfillmentOrder::find($order['order_id'])->status);
        $this->assertSame(WorkOrder::CANCELLED, WorkOrder::find($woId)->status); // compensated
        $this->assertSame(Subscription::TERMINATED, Subscription::find($fresh->subscription_id)->status_code);
    }

    public function test_fraud_suspected_flag_blocks_activation(): void
    {
        // R-ILM-F-4: a provisioning-affecting account flag (FRAUD_SUSPECTED) blocks activation.
        $this->seed(AccountFlagCatalogSeeder::class);
        $custId = Id::make('cust');
        Customer::query()->create([
            'customer_id' => $custId, 'operator_code' => 'WIK', 'type' => 'RES', 'name' => 'Flagged',
            'primary_msisdn' => '+254700555666', 'kyc_status' => 'APPROVED',
        ]);
        $account = CustomerAccount::query()->create([
            'account_id' => 'ACC-FR', 'operator_code' => 'WIK', 'account_number' => '009-7', 'customer_id' => $custId,
            'service_address' => 'Nairobi', 'status' => 'ACTIVE', 'sub_status' => 'active',
        ]);
        app(AccountService::class)->setFlag($account, 'FRAUD_SUSPECTED');

        $order = $this->postJson('/api/fulfillment-orders', [
            'customer_id' => $custId, 'account_id' => 'ACC-FR', 'homepass_id' => 'hp_fr', 'package_ref' => 'pkg_x',
        ], ['Idempotency-Key' => 'order-fr'])->json('order');
        $this->drain(); // park await_install
        $this->postJson("/api/fulfillment-orders/{$order['order_id']}/complete", [], ['Idempotency-Key' => 'complete-fr'])->assertOk();
        $this->drain(); // KYC gate passes (APPROVED) -> activation BLOCKED by the fraud flag

        $fresh = FulfillmentOrder::find($order['order_id']);
        $this->assertNotSame('COMPLETED', $fresh->status);
        $this->assertSame('PENDING_ACTIVATION', Subscription::find($fresh->subscription_id)->status_code); // never activated
    }

    public function test_capture_requires_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('CUSTOMER_CARE_AGENT');
        Sanctum::actingAs($user);

        $this->postJson('/api/fulfillment-orders', [
            'customer_id' => 'c', 'account_id' => 'a', 'package_ref' => 'p',
        ])->assertForbidden();
    }

    public function test_deposit_required_order_parks_awaiting_payment_then_resumes_on_deposit(): void
    {
        $resp = $this->postJson('/api/fulfillment-orders', [
            'customer_id' => 'cust_dep', 'account_id' => 'acct_dep', 'homepass_id' => 'hp_1',
            'package_ref' => 'pkg_triple', 'deposit_required' => true, // no payment_ref → unpaid
        ], ['Idempotency-Key' => 'order-dep'])->assertStatus(201);
        $orderId = $resp->json('order.order_id');

        // The flow runs to the deposit gate and parks awaiting payment — no install WO yet.
        $this->drain();
        $this->assertSame('AWAITING_PAYMENT', FulfillmentOrder::find($orderId)->status);
        $this->assertDatabaseMissing('work_order', ['source_ref' => $orderId]);

        // Deposit received → correlate, the flow proceeds to create the install WO.
        app(OrderCaptureService::class)->confirmDepositPaid(FulfillmentOrder::find($orderId));
        $this->drain();
        $this->assertSame('AWAITING_INSTALL', FulfillmentOrder::find($orderId)->status);
    }
}
