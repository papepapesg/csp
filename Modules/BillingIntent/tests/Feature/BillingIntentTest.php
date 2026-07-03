<?php

namespace Modules\Billing\Intent\Tests\Feature;

use App\Foundation\Support\Context;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Intent\Models\BillingIntent;
use Modules\Billing\Intent\Services\BillingIntentService;
use Modules\Billing\Wallet\Services\WalletService;
use Modules\Billing\Wallet\Database\Seeders\WalletCatalogSeeder;
use Tests\TestCase;

/**
 * BIL-01 billing-intent settlement channels — the module's own contract,
 * exercised at service level with NO BillableEvent catalog deployed (catalog
 * governance — unknown event, sign policy, applicability skip, state
 * callbacks — is covered by BillableEventCatalogTest).
 *
 * An intent records the charge/credit a subscription operation implies and
 * settles its money side through exactly ONE channel:
 *  - NONE    nothing to charge (zero amount / applicability skip) → CONFIRMED;
 *  - WALLET  prepaid: debit the wallet inline (CONFIRMED) or park PENDING
 *            until a top-up covers it;
 *  - INVOICE postpaid: raise a fee invoice; pay-first parks PENDING until it
 *            is paid, otherwise the intent proceeds as CHARGED;
 *  - CREDIT  negative amount → posted to the account credit balance, CONFIRMED.
 * PENDING is the pay-first gate: the operation workflow parks on
 * AWAITING_PAYMENT until confirm() (payment / top-up) releases it.
 */
class BillingIntentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WalletCatalogSeeder::class); // MONEY_KES wallet definitions for the prepaid channel
        Context::setOperatorCode('WIK');
    }

    private function emit(array $overrides = []): BillingIntent
    {
        return app(BillingIntentService::class)->emit($overrides + [
            'subscription_id' => 'sub_bint',
            'account_id' => 'acc_bint',
            'operation_id' => 'op_bint',
            'intent_type' => 'PRORATION',
            'amount' => 0,
        ]);
    }

    /**
     * EXPECTATION — nothing to charge, nothing to gate.
     * Given an operation whose billing impact is zero,
     * when the intent is emitted,
     * then it CONFIRMS immediately on the NONE channel (no invoice, no wallet
     * touch), and the emission is still announced (an intent is an audit fact
     * even when free).
     */
    public function test_zero_amount_intent_confirms_immediately_with_nothing_to_charge(): void
    {
        $intent = $this->emit(['amount' => 0]);

        $this->assertSame(BillingIntent::CONFIRMED, $intent->status);
        $this->assertSame(BillingIntent::CHANNEL_NONE, $intent->settlement_channel);
        $this->assertNotNull($intent->confirmed_at);
        $this->assertNull($intent->invoice_id);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionBillingIntentEmitted']);
    }

    /**
     * EXPECTATION — postpaid charge without pay-first: bill and move on.
     * Given a 250 postpaid charge with pay_first = false,
     * when the intent is emitted,
     * then a STANDARD fee invoice is raised for 250 — a REAL receivable
     * (amount_due 250, unlike credit/debit notes) — the intent links to it and
     * proceeds as CHARGED: the operation is not gated on payment; collection
     * follows the normal invoice lifecycle (dunning included).
     */
    public function test_postpaid_charge_raises_a_fee_invoice_and_proceeds_without_gating(): void
    {
        $intent = $this->emit(['amount' => 250, 'pay_first' => false, 'description' => 'Upgrade proration']);

        $this->assertSame(BillingIntent::CHARGED, $intent->status);
        $this->assertSame(BillingIntent::CHANNEL_INVOICE, $intent->settlement_channel);
        $this->assertNotNull($intent->invoice_id);
        $this->assertDatabaseHas('invoice', ['invoice_id' => $intent->invoice_id, 'type' => 'STANDARD', 'total_amount' => 250.00, 'amount_due' => 250.00]);
    }

    /**
     * EXPECTATION — pay-first is the payment gate, released by confirm().
     * Given a 500 postpaid charge with pay_first = true,
     * when the intent is emitted,
     * then it parks PENDING with its fee invoice raised (the workflow waits on
     * AWAITING_PAYMENT).
     * When the invoice is settled and confirm() runs (ConfirmBillingIntentOnPayment),
     * then the intent CONFIRMS, stamps confirmed_at, and announces it —
     * and a second confirm() is idempotent: no state change, no duplicate event.
     */
    public function test_pay_first_postpaid_charge_parks_until_confirmed(): void
    {
        $intent = $this->emit(['amount' => 500, 'pay_first' => true]);

        $this->assertSame(BillingIntent::PENDING, $intent->status);
        $this->assertSame(BillingIntent::CHANNEL_INVOICE, $intent->settlement_channel);
        $this->assertTrue($intent->pay_first);
        $this->assertNull($intent->confirmed_at);

        app(BillingIntentService::class)->confirm($intent);
        $intent->refresh();
        $this->assertSame(BillingIntent::CONFIRMED, $intent->status);
        $this->assertNotNull($intent->confirmed_at);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionBillingIntentConfirmed']);

        // Idempotent: confirming an already-confirmed intent is a no-op.
        app(BillingIntentService::class)->confirm($intent->refresh());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'SubscriptionBillingIntentConfirmed')->count());
    }

    /**
     * EXPECTATION — prepaid never raises an invoice: the wallet pays, inline.
     * Given a prepaid subscription whose wallet holds 500,
     * when a 200 charge intent is emitted,
     * then the wallet is debited to 300 in the same call and the intent
     * CONFIRMS on the WALLET channel — no invoice, no gate, the operation
     * proceeds immediately.
     */
    public function test_prepaid_charge_settles_inline_from_the_wallet(): void
    {
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->ensureWallet('sub_pp_ok', 'MONEY_KES'), 500, 'TOPUP');

        $intent = $this->emit(['subscription_id' => 'sub_pp_ok', 'billing_mode' => 'PREPAID', 'amount' => 200]);

        $this->assertSame(BillingIntent::CONFIRMED, $intent->status);
        $this->assertSame(BillingIntent::CHANNEL_WALLET, $intent->settlement_channel);
        $this->assertNull($intent->invoice_id);
        $this->assertDatabaseHas('wallet', ['subscription_id' => 'sub_pp_ok', 'wallet_code' => 'MONEY_KES', 'balance' => 300.00]);
    }

    /**
     * EXPECTATION — a short wallet parks the intent; settlement is all-or-nothing.
     * Given a prepaid wallet holding only 100,
     * when a 400 charge intent is emitted,
     * then NOTHING is debited (no partial take — the balance stays 100) and the
     * intent parks PENDING on the WALLET channel with pay_first forced true, so
     * the operation waits on AWAITING_PAYMENT until a top-up settles it
     * (ConfirmPrepaidIntentOnTopup re-attempts on every WalletToppedUp).
     */
    public function test_prepaid_charge_parks_pending_when_the_wallet_is_short(): void
    {
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->ensureWallet('sub_pp_short', 'MONEY_KES'), 100, 'TOPUP');

        $intent = $this->emit(['subscription_id' => 'sub_pp_short', 'billing_mode' => 'PREPAID', 'amount' => 400]);

        $this->assertSame(BillingIntent::PENDING, $intent->status);
        $this->assertSame(BillingIntent::CHANNEL_WALLET, $intent->settlement_channel);
        $this->assertTrue($intent->pay_first); // forced: an unpaid prepaid charge always gates
        $this->assertDatabaseHas('wallet', ['subscription_id' => 'sub_pp_short', 'balance' => 100.00]); // untouched
    }

    /**
     * EXPECTATION — a negative intent is money we owe: it lands on the account
     * credit balance, immediately.
     * Given a -150 intent (e.g. downgrade proration / deposit refund),
     * when it is emitted,
     * then 150 is posted to the account credit balance (consumable by future
     * invoices) and the intent CONFIRMS on the CREDIT channel — a credit never
     * gates the operation.
     */
    public function test_negative_amount_posts_account_credit_and_confirms(): void
    {
        $intent = $this->emit(['account_id' => 'acc_refund', 'amount' => -150]);

        $this->assertSame(BillingIntent::CONFIRMED, $intent->status);
        $this->assertSame(BillingIntent::CHANNEL_CREDIT, $intent->settlement_channel);
        $this->assertDatabaseHas('account_credit_balance', ['account_id' => 'acc_refund', 'balance' => 150.00]);
    }
}
