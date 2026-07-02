<?php

namespace Modules\Billing\Intent\Tests\Feature;

use App\Foundation\Errors\DomainException;
use App\Foundation\Support\Context;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Billing\Intent\Database\Seeders\BillableEventSeeder;
use Modules\Billing\Intent\Models\BillableEvent;
use Modules\Billing\Intent\Models\BillingIntent;
use Modules\Billing\Intent\Services\BillingIntentService;
use Modules\Subscription\Models\Subscription;
use Tests\TestCase;

/**
 * BIL-CFG-01: the BillableEvent catalog governs what BIL-01 may charge —
 * admin CRUD + lifecycle, integrity rules, and runtime enforcement on
 * billing intents (unknown event rejected, applicability skips, sign policy).
 */
class BillableEventCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(BillableEventSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
        Context::setOperatorCode('WIK');
    }

    public function test_catalog_crud_and_lifecycle(): void
    {
        // Create DRAFT → activate → events list it.
        $res = $this->postJson('/api/billing/billable-events', [
            'code' => 'RESTRICTION_LIFT_FEE',
            'description' => 'Fee to lift an outgoing-voice restriction',
            'category_code' => 'LIFECYCLE_FEE',
            'trigger_type' => 'SAGA_INTENT',
            'trigger_intent_code' => 'RESTRICTION_REMOVE_INTENT',
            'amount_sign_policy' => 'POSITIVE_ONLY',
        ], ['Idempotency-Key' => 'bev-1'])->assertCreated();
        $id = $res->json('id');
        $this->assertSame('DRAFT', $res->json('status'));

        $this->postJson("/api/billing/billable-events/{$id}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE');

        // Sign policy is immutable once ACTIVE (retire + re-create instead).
        $this->patchJson("/api/billing/billable-events/{$id}", ['amount_sign_policy' => 'SIGNED'])
            ->assertStatus(422)->assertJsonPath('errorCode', 'FIELD_IMMUTABLE_WHEN_ACTIVE');
        // …but the description may evolve.
        $this->patchJson("/api/billing/billable-events/{$id}", ['description' => 'Lift fee v2'])->assertOk();

        $this->postJson("/api/billing/billable-events/{$id}/retire")->assertOk()->assertJsonPath('status', 'RETIRED');

        // Filterable admin listing + categories endpoint.
        $this->getJson('/api/billing/billable-events?triggerType=SAGA_INTENT&status=ACTIVE')->assertOk();
        $this->getJson('/api/billing/billable-event-categories')->assertOk()->assertJsonPath('items.0.operator_code', 'WIK');
    }

    public function test_integrity_rules_reject_bad_definitions(): void
    {
        // Duplicate code per operator.
        $this->postJson('/api/billing/billable-events', [
            'code' => 'PRORATION', 'description' => 'dup', 'category_code' => 'PRORATION',
            'trigger_type' => 'SAGA_INTENT', 'trigger_intent_code' => 'X',
        ], ['Idempotency-Key' => 'bev-dup'])->assertStatus(409);

        // SAGA_INTENT requires its intent code.
        $this->postJson('/api/billing/billable-events', [
            'code' => 'NO_INTENT', 'description' => 'x', 'category_code' => 'VAS',
            'trigger_type' => 'SAGA_INTENT',
        ], ['Idempotency-Key' => 'bev-noint'])->assertStatus(422)->assertJsonPath('errorCode', 'TRIGGER_INTENT_REQUIRED');

        // Category must be an ACTIVE category of the SAME operator.
        $this->postJson('/api/billing/billable-events', [
            'code' => 'BAD_CAT', 'description' => 'x', 'category_code' => 'NOT_A_CATEGORY',
            'trigger_type' => 'ADMIN_ACTION',
        ], ['Idempotency-Key' => 'bev-badcat'])->assertStatus(422)->assertJsonPath('errorCode', 'CATEGORY_OPERATOR_MISMATCH');

        // A state callback only fires after a confirmed charge → pay-first required.
        $this->postJson('/api/billing/billable-events', [
            'code' => 'CB_NO_PAYFIRST', 'description' => 'x', 'category_code' => 'RECONNECTION_FEE',
            'trigger_type' => 'EXTERNAL_PAYMENT', 'pay_first_required' => false,
            'state_callback' => ['transitionCode' => 'RECONNECT_AFTER_FEE', 'targetStatus' => 'ACTIVE'],
        ], ['Idempotency-Key' => 'bev-cb'])->assertStatus(422)->assertJsonPath('errorCode', 'PAY_FIRST_REQUIRED_FOR_STATE_CALLBACK');

        // A state callback is validated at AUTHORING time — it fires deep inside a payment
        // confirmation, where a bad target would silently corrupt the subscription status.
        // The transitionCode (audit label of the gated SUB-LM transition) is mandatory…
        $this->postJson('/api/billing/billable-events', [
            'code' => 'CB_NO_TRANSITION', 'description' => 'x', 'category_code' => 'RECONNECTION_FEE',
            'trigger_type' => 'EXTERNAL_PAYMENT', 'pay_first_required' => true,
            'state_callback' => ['targetStatus' => 'ACTIVE'],
        ], ['Idempotency-Key' => 'bev-cb-notrans'])->assertStatus(422)->assertJsonPath('errorCode', 'STATE_CALLBACK_TRANSITION_REQUIRED');

        // …and the targetStatus must be a real SUB-LM rest state (typos rejected here, not at pay time).
        $this->postJson('/api/billing/billable-events', [
            'code' => 'CB_BAD_TARGET', 'description' => 'x', 'category_code' => 'RECONNECTION_FEE',
            'trigger_type' => 'EXTERNAL_PAYMENT', 'pay_first_required' => true,
            'state_callback' => ['transitionCode' => 'RECONNECT_AFTER_FEE', 'targetStatus' => 'AKTIVE'],
        ], ['Idempotency-Key' => 'bev-cb-badtarget'])->assertStatus(422)->assertJsonPath('errorCode', 'STATE_CALLBACK_TARGET_INVALID');

        // A PENDING_* transition marker is not a rest state — a callback cannot park a subscription mid-transition.
        $this->postJson('/api/billing/billable-events', [
            'code' => 'CB_TRANSIENT_TARGET', 'description' => 'x', 'category_code' => 'RECONNECTION_FEE',
            'trigger_type' => 'EXTERNAL_PAYMENT', 'pay_first_required' => true,
            'state_callback' => ['transitionCode' => 'RECONNECT_AFTER_FEE', 'targetStatus' => 'PENDING_ACTIVATION'],
        ], ['Idempotency-Key' => 'bev-cb-transient'])->assertStatus(422)->assertJsonPath('errorCode', 'STATE_CALLBACK_TARGET_INVALID');

        // A well-formed callback (known transition label + rest-state target) is accepted.
        $this->postJson('/api/billing/billable-events', [
            'code' => 'CB_VALID', 'description' => 'x', 'category_code' => 'RECONNECTION_FEE',
            'trigger_type' => 'EXTERNAL_PAYMENT', 'pay_first_required' => true,
            'state_callback' => ['transitionCode' => 'RECONNECT_AFTER_FEE', 'targetStatus' => 'ACTIVE'],
        ], ['Idempotency-Key' => 'bev-cb-valid'])->assertCreated();
    }

    public function test_billing_intent_is_validated_against_the_catalog(): void
    {
        $intents = app(BillingIntentService::class);

        // Known event charges normally.
        $intent = $intents->emit([
            'subscription_id' => 'sub_cat_1', 'account_id' => 'acc_cat_1',
            'intent_type' => 'PAUSE_FEE', 'amount' => 100, 'pay_first' => false,
        ]);
        $this->assertSame('INVOICE', $intent->settlement_channel);

        // Unknown event is rejected once the operator governs through the catalog.
        try {
            $intents->emit(['subscription_id' => 'sub_cat_2', 'account_id' => 'acc_cat_2', 'intent_type' => 'NOT_IN_CATALOG', 'amount' => 50]);
            $this->fail('expected UNKNOWN_BILLABLE_EVENT');
        } catch (DomainException $e) {
            $this->assertSame('UNKNOWN_BILLABLE_EVENT', $e->errorCode);
        }

        // Sign policy: PAUSE_FEE is POSITIVE_ONLY — a negative amount violates it.
        try {
            $intents->emit(['subscription_id' => 'sub_cat_3', 'account_id' => 'acc_cat_3', 'intent_type' => 'PAUSE_FEE', 'amount' => -10]);
            $this->fail('expected AMOUNT_SIGN_VIOLATION');
        } catch (DomainException $e) {
            $this->assertSame('AMOUNT_SIGN_VIOLATION', $e->errorCode);
        }

        // Applicability skip (R-B-5): a PREPAID_ONLY event against a POSTPAID
        // subscription is skipped — confirmed without charging, not failed.
        BillableEvent::query()->where('operator_code', 'WIK')->where('code', 'PAUSE_FEE')->update(['applicability' => 'PREPAID_ONLY']);
        $skipped = $intents->emit([
            'subscription_id' => 'sub_cat_4', 'account_id' => 'acc_cat_4',
            'billing_mode' => 'POSTPAID', 'intent_type' => 'PAUSE_FEE', 'amount' => 100,
        ]);
        $this->assertSame('CONFIRMED', $skipped->status);
        $this->assertSame('NONE', $skipped->settlement_channel);
        $this->assertNull($skipped->invoice_id);
    }

    public function test_paid_state_callback_transitions_the_subscription(): void
    {
        // A suspended-for-non-payment subscription awaiting its reconnection fee.
        $sub = Subscription::query()->create([
            'operator_code' => 'WIK', 'customer_id' => 'cust_sc', 'account_id' => 'acc_sc',
            'homepass_id' => 'hp_sc', 'package_ref' => 'pkg_sc', 'status_code' => Subscription::SUSPENDED,
            'billing_mode' => 'POSTPAID',
        ]);

        // The operator's reconnection fee is pay-first and its state_callback flips the
        // subscription back to ACTIVE once paid (seeded by BillableEventSeeder).
        BillableEvent::query()->where('operator_code', 'WIK')->where('code', 'RECONNECTION_FEE_AFTER_DUNNING')
            ->update([
                'status' => BillableEvent::ACTIVE, 'pay_first_required' => true,
                'state_callback' => ['transitionCode' => 'RECONNECT_AFTER_FEE', 'targetStatus' => 'ACTIVE'],
            ]);

        // Emitting the fee parks PENDING (pay-first) and pins the callback; no transition yet.
        $intent = app(BillingIntentService::class)->emit([
            'subscription_id' => $sub->subscription_id, 'account_id' => 'acc_sc',
            'intent_type' => 'RECONNECTION_FEE_AFTER_DUNNING', 'amount' => 500,
        ]);
        $this->assertSame(BillingIntent::PENDING, $intent->status);
        $this->assertSame('ACTIVE', $intent->state_callback['targetStatus']);
        $this->assertSame(Subscription::SUSPENDED, $sub->refresh()->status_code); // still suspended

        // Payment confirms the intent → the gated SUB-LM transition fires.
        app(BillingIntentService::class)->confirm($intent->refresh());
        $this->assertSame(Subscription::ACTIVE, $sub->refresh()->status_code);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionActivated']);
    }
}
