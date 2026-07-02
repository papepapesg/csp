<?php

namespace Modules\Subscription\Tests\Feature;

use App\Foundation\Errors\DomainException;
use App\Foundation\Support\Context;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Services\SubscriptionService;
use Tests\TestCase;

/**
 * SUB-LM-01 transition map — transitionStatus() is the single write point for
 * subscription status, and it now refuses illegal jumps whoever the caller is
 * (workflow handler, dunning, a BIL-01 state callback). The canonical rule:
 * TERMINATED never resurrects — it only archives to RETIRED.
 */
class SubscriptionTransitionMapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Context::setOperatorCode('WIK');
    }

    private function subscription(string $status): Subscription
    {
        return Subscription::query()->create([
            'operator_code' => 'WIK', 'customer_id' => 'cust_lm', 'account_id' => 'acc_lm',
            'homepass_id' => 'hp_lm', 'package_ref' => 'pkg_lm',
            'status_code' => $status, 'billing_mode' => 'POSTPAID',
        ]);
    }

    /**
     * EXPECTATION — the legal lifecycle flows normally.
     * A SUSPENDED subscription may reactivate (dunning recovery, reconnection
     * fee paid), and a TERMINATED one may archive to RETIRED. Re-committing the
     * state a subscription is already in is allowed — commits are idempotent.
     */
    public function test_legal_transitions_commit_and_emit(): void
    {
        $svc = app(SubscriptionService::class);

        $sub = $this->subscription(Subscription::SUSPENDED);
        $svc->transitionStatus($sub, Subscription::ACTIVE);
        $this->assertSame(Subscription::ACTIVE, $sub->refresh()->status_code);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionActivated']);

        $dead = $this->subscription(Subscription::TERMINATED);
        $svc->transitionStatus($dead, Subscription::RETIRED);
        $this->assertSame(Subscription::RETIRED, $dead->refresh()->status_code);

        // Idempotent re-commit of the current state is not an illegal jump.
        $svc->transitionStatus($sub->refresh(), Subscription::ACTIVE);
        $this->assertSame(Subscription::ACTIVE, $sub->refresh()->status_code);
    }

    /**
     * EXPECTATION — TERMINATED never resurrects.
     * Whatever the caller (an admin action, a late payment, a misauthored
     * callback), driving a TERMINATED subscription back to ACTIVE is refused
     * with TRANSITION_NOT_ALLOWED and the row is untouched.
     */
    public function test_terminated_never_resurrects(): void
    {
        $dead = $this->subscription(Subscription::TERMINATED);

        try {
            app(SubscriptionService::class)->transitionStatus($dead, Subscription::ACTIVE);
            $this->fail('expected TRANSITION_NOT_ALLOWED');
        } catch (DomainException $e) {
            $this->assertSame('TRANSITION_NOT_ALLOWED', $e->errorCode);
        }
        $this->assertSame(Subscription::TERMINATED, $dead->refresh()->status_code);
    }

    /**
     * EXPECTATION — RETIRED is the end of the line.
     * An archived subscription accepts no further transitions at all.
     */
    public function test_retired_accepts_no_transition(): void
    {
        $archived = $this->subscription(Subscription::RETIRED);

        try {
            app(SubscriptionService::class)->transitionStatus($archived, Subscription::ACTIVE);
            $this->fail('expected TRANSITION_NOT_ALLOWED');
        } catch (DomainException $e) {
            $this->assertSame('TRANSITION_NOT_ALLOWED', $e->errorCode);
        }
        $this->assertSame(Subscription::RETIRED, $archived->refresh()->status_code);
    }

    /**
     * EXPECTATION — a PENDING_* marker commits only where its operation may land.
     * PENDING_UPGRADE commits to ACTIVE; committing it to TERMINATED is not a
     * move the upgrade operation can make.
     */
    public function test_pending_states_commit_only_to_their_operations_targets(): void
    {
        $sub = $this->subscription(Subscription::PENDING_UPGRADE);
        app(SubscriptionService::class)->transitionStatus($sub, Subscription::ACTIVE);
        $this->assertSame(Subscription::ACTIVE, $sub->refresh()->status_code);

        $other = $this->subscription(Subscription::PENDING_UPGRADE);
        try {
            app(SubscriptionService::class)->transitionStatus($other, Subscription::TERMINATED);
            $this->fail('expected TRANSITION_NOT_ALLOWED');
        } catch (DomainException $e) {
            $this->assertSame('TRANSITION_NOT_ALLOWED', $e->errorCode);
        }
    }
}
