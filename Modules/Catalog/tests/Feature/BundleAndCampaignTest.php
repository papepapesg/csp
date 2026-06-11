<?php

namespace Modules\Catalog\Tests\Feature;

use App\Foundation\Support\Id;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Catalog\Models\Discount;
use Modules\Catalog\Models\Package;
use Modules\Catalog\Models\PackageVersion;
use Tests\TestCase;

/**
 * SIP-04 bundle launch (lifecycle + launch checks + availability gating + migration
 * preview) and SIP-05 campaigns (eligibility + unique redemption binding a SIP-03
 * discount assignment).
 */
class BundleAndCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    private function activePackage(string $code): string
    {
        $pkgId = Id::make('pkg');
        $verId = Id::make('pkv');
        Package::query()->create(['id' => $pkgId, 'operator_code' => 'WIK', 'code' => $code, 'name' => $code, 'status' => 'ACTIVE', 'current_version_id' => $verId]);
        PackageVersion::query()->create(['id' => $verId, 'package_id' => $pkgId, 'price' => 1000, 'currency' => 'KES', 'effective_from' => now(), 'status' => 'ACTIVE']);

        return $pkgId;
    }

    private function createBundle(string $code, array $packageRefs, array $extra = []): array
    {
        return $this->postJson('/api/commercial-bundles', array_merge([
            'bundle_code' => $code,
            'display_name' => "Bundle {$code}",
            'bundle_type' => 'ACQUISITION',
            'components' => array_map(fn ($ref) => ['package_ref' => $ref, 'mandatory' => true], $packageRefs),
            'availability' => [['channel_code' => 'SALES_APP', 'franchise_id' => 'fr_nrb_002']],
        ], $extra), ['Idempotency-Key' => "bun-{$code}"])->assertCreated()->json();
    }

    public function test_bundle_launch_lifecycle_with_validation_gate(): void
    {
        $pkg = $this->activePackage('PKG_FIBER_100M');
        $bundle = $this->createBundle('FIBER_TV_STARTER', [$pkg]);
        $this->assertSame('DRAFT', $bundle['status']);

        // DRAFT -> READY_FOR_REVIEW (validation passes) -> APPROVED -> ACTIVE.
        $this->postJson("/api/commercial-bundles/{$bundle['bundle_id']}/submit-review")->assertOk()->assertJsonPath('status', 'READY_FOR_REVIEW');
        $this->postJson("/api/commercial-bundles/{$bundle['bundle_id']}/approve", ['decisionComment' => 'ok'])->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->postJson("/api/commercial-bundles/{$bundle['bundle_id']}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE');

        // Auditable launch-check trail exists (R-SIP-BUN-02/03).
        $this->assertDatabaseHas('commercial_bundle_launch_check', ['bundle_id' => $bundle['bundle_id'], 'check_code' => 'PACKAGE_ACTIVE', 'check_status' => 'PASS']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'CommercialBundleActivated']);
    }

    public function test_bundle_with_inactive_package_cannot_submit_for_review(): void
    {
        // Package exists but is DRAFT -> PACKAGE_ACTIVE check FAILS (R-SIP-BUN-03).
        $pkgId = Id::make('pkg');
        Package::query()->create(['id' => $pkgId, 'operator_code' => 'WIK', 'code' => 'PKG_DRAFT', 'name' => 'x', 'status' => 'DRAFT']);
        $bundle = $this->createBundle('BROKEN_BUNDLE', [$pkgId]);

        $this->postJson("/api/commercial-bundles/{$bundle['bundle_id']}/submit-review")
            ->assertStatus(422)->assertJsonPath('errorCode', 'BUNDLE_VALIDATION_FAILED');
        $this->assertDatabaseHas('commercial_bundle_launch_check', ['bundle_id' => $bundle['bundle_id'], 'check_status' => 'FAIL']);
    }

    public function test_available_bundles_are_gated_by_channel_and_franchise(): void
    {
        $pkg = $this->activePackage('PKG_AV');
        $bundle = $this->createBundle('AV_BUNDLE', [$pkg]);
        $this->postJson("/api/commercial-bundles/{$bundle['bundle_id']}/submit-review")->assertOk();
        $this->postJson("/api/commercial-bundles/{$bundle['bundle_id']}/approve")->assertOk();
        $this->postJson("/api/commercial-bundles/{$bundle['bundle_id']}/activate")->assertOk();

        // Right channel + franchise -> visible (R-SIP-BUN-10).
        $hit = $this->getJson('/api/commercial-bundles/available?channelCode=SALES_APP&franchiseId=fr_nrb_002')->assertOk()->json('bundles');
        $this->assertCount(1, $hit);
        $this->assertSame('AV_BUNDLE', $hit[0]['bundle_code']);

        // Wrong channel -> hidden.
        $this->assertCount(0, $this->getJson('/api/commercial-bundles/available?channelCode=SELF_CARE')->assertOk()->json('bundles'));
    }

    public function test_migration_preview_authorizes_path_but_delegates_execution(): void
    {
        $pkgA = $this->activePackage('PKG_OLD');
        $pkgB = $this->activePackage('PKG_NEW');
        $old = $this->createBundle('OLD_FIBER', [$pkgA]);
        $new = $this->createBundle('NEW_FIBER', [$pkgB]);

        $this->postJson("/api/commercial-bundles/{$old['bundle_id']}/migration-rules", [
            'target_bundle_code' => 'NEW_FIBER', 'movement_type' => 'MIGRATION',
            'allowed_channel_json' => ['BACKOFFICE'], 'requires_customer_consent' => true,
        ])->assertCreated();

        // Allowed channel -> authorized, with the owning SUB workflow named (R-SIP-BUN-09).
        $this->postJson('/api/commercial-bundles/migration-preview', [
            'sourceBundleCode' => 'OLD_FIBER', 'targetBundleCode' => 'NEW_FIBER', 'channelCode' => 'BACKOFFICE',
        ])->assertOk()->assertJsonPath('allowed', true)->assertJsonPath('owningWorkflow', 'SUB-WF-MIGRATION-01');

        // Self-care not in allowed channels -> refused.
        $this->postJson('/api/commercial-bundles/migration-preview', [
            'sourceBundleCode' => 'OLD_FIBER', 'targetBundleCode' => 'NEW_FIBER', 'channelCode' => 'SELF_CARE',
        ])->assertOk()->assertJsonPath('allowed', false)->assertJsonPath('reason', 'CHANNEL_NOT_ALLOWED');

        // No rule at all -> refused (R-SIP-BUN-08).
        $this->postJson('/api/commercial-bundles/migration-preview', [
            'sourceBundleCode' => 'NEW_FIBER', 'targetBundleCode' => 'OLD_FIBER', 'channelCode' => 'BACKOFFICE',
        ])->assertOk()->assertJsonPath('reason', 'NO_ACTIVE_MIGRATION_RULE');
    }

    public function test_campaign_pause_and_end_lifecycle(): void
    {
        $c = $this->postJson('/api/campaigns', ['code' => 'LC_TEST', 'name' => 'Lifecycle',
            'offers' => [['offer_type' => 'MESSAGE_ONLY']], // R-SIP-CAMP-03: a message-only purpose satisfies the offer rule
        ], ['Idempotency-Key' => 'camp-lc'])->assertCreated()->json();
        $this->postJson("/api/campaigns/{$c['campaign_id']}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->postJson("/api/campaigns/{$c['campaign_id']}/pause")->assertOk()->assertJsonPath('status', 'PAUSED');
        $this->postJson("/api/campaigns/{$c['campaign_id']}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->postJson("/api/campaigns/{$c['campaign_id']}/end")->assertOk()->assertJsonPath('status', 'ENDED');
        // Ended campaigns are no longer eligible.
        $this->postJson("/api/campaigns/{$c['campaign_id']}/check-eligibility", ['channelCode' => 'SALES_APP'])
            ->assertOk()->assertJsonPath('eligible', false);
    }

    public function test_campaign_activation_is_gated_by_validation(): void
    {
        // An empty campaign cannot activate (R-SIP-CAMP-03 needs an offer).
        $empty = $this->postJson('/api/campaigns', ['code' => 'EMPTY_C', 'name' => 'Empty'], ['Idempotency-Key' => 'c-empty'])->json();
        $this->postJson("/api/campaigns/{$empty['campaign_id']}/activate")
            ->assertStatus(422)->assertJsonPath('errorCode', 'CAMPAIGN_VALIDATION_FAILED');

        // A discount offer pointing at an unknown discount fails validation (R-SIP-CAMP-04).
        $bad = $this->postJson('/api/campaigns', [
            'code' => 'BAD_DISC', 'name' => 'Bad discount ref',
            'offers' => [['offer_type' => 'DISCOUNT', 'discount_code' => 'NO_SUCH_DISCOUNT']],
        ], ['Idempotency-Key' => 'c-bad'])->json();
        $this->postJson("/api/campaigns/{$bad['campaign_id']}/validate")
            ->assertOk()->assertJsonPath('checks.0.status', 'FAIL');
        $this->postJson("/api/campaigns/{$bad['campaign_id']}/activate")->assertStatus(422);
    }

    public function test_campaign_eligibility_and_unique_redemption_with_discount_binding(): void
    {
        Discount::query()->create([
            'discount_id' => Id::make('disc'), 'operator_code' => 'WIK', 'code' => 'ACQ_FREE_INSTALL',
            'name' => 'Free install', 'discount_type' => 'FIXED', 'value' => 2500, 'status' => 'ACTIVE',
        ]);

        $campaign = $this->postJson('/api/campaigns', [
            'code' => 'ACQ_NRB_JULY', 'name' => 'Free Installation July', 'campaign_type' => 'ACQUISITION',
            'starts_at' => now()->subDay()->toDateTimeString(), 'ends_at' => now()->addMonth()->toDateTimeString(),
            'max_participants' => 2,
            'offers' => [['offer_type' => 'DISCOUNT', 'discount_code' => 'ACQ_FREE_INSTALL', 'assignment_scope_type' => 'ORDER']],
            'target_rules' => [['rule_type' => 'FRANCHISE', 'operator' => 'IN', 'rule_value_json' => ['fr_nrb_002'], 'hard_exclusion' => true]],
            'channels' => ['SALES_APP'],
        ], ['Idempotency-Key' => 'camp-1'])->assertCreated()->json();

        $this->postJson("/api/campaigns/{$campaign['campaign_id']}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE');

        // Wrong franchise -> hard rule blocks; wrong channel -> blocked.
        $this->postJson("/api/campaigns/{$campaign['campaign_id']}/check-eligibility", ['channelCode' => 'SALES_APP', 'franchiseId' => 'fr_msa_009'])
            ->assertOk()->assertJsonPath('eligible', false);
        $this->postJson("/api/campaigns/{$campaign['campaign_id']}/check-eligibility", ['channelCode' => 'SELF_CARE', 'franchiseId' => 'fr_nrb_002'])
            ->assertOk()->assertJsonPath('eligible', false);

        // Eligible -> participate -> REDEEMED with a SIP-03 assignment bound.
        $this->postJson("/api/campaigns/{$campaign['campaign_id']}/check-eligibility", ['channelCode' => 'SALES_APP', 'franchiseId' => 'fr_nrb_002'])
            ->assertOk()->assertJsonPath('eligible', true);
        $p = $this->postJson("/api/campaigns/{$campaign['campaign_id']}/participate", [
            'participant_type' => 'ORDER', 'participant_ref_id' => 'ord_001',
            'channelCode' => 'SALES_APP', 'franchiseId' => 'fr_nrb_002', 'customerId' => 'cust_1',
        ], ['Idempotency-Key' => 'part-1'])->assertCreated()->json();
        $this->assertSame('REDEEMED', $p['status']);
        $this->assertDatabaseHas('discount_assignment', ['assignment_id' => $p['assignment_id'], 'discount_code' => 'ACQ_FREE_INSTALL', 'campaign_code' => 'ACQ_NRB_JULY']);

        // Duplicate redemption is refused (unique participation).
        $this->postJson("/api/campaigns/{$campaign['campaign_id']}/participate", [
            'participant_type' => 'ORDER', 'participant_ref_id' => 'ord_001',
            'channelCode' => 'SALES_APP', 'franchiseId' => 'fr_nrb_002',
        ], ['Idempotency-Key' => 'part-dup'])->assertStatus(409);
    }
}
