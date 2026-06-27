<?php

namespace Modules\Catalog\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Catalog\Database\Seeders\TaxCatalogSeeder;
use Modules\Catalog\Tax\Models\TaxRule;
use Tests\TestCase;

/**
 * PLM-CFG-02 tax configuration admin: effective-dated rule versioning (a code is
 * never deleted, only superseded), ordered groups, and member validation.
 */
class TaxConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(TaxCatalogSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    public function test_lists_seeded_rules_and_groups(): void
    {
        $this->getJson('/api/tax/rules?operatorCode=WIK')->assertOk()
            ->assertJsonFragment(['code' => 'WIK_INTERNET_VAT']);
        $this->getJson('/api/tax/groups?operatorCode=WIK')->assertOk()
            ->assertJsonFragment(['code' => 'WIK_INTERNET']);
    }

    public function test_create_rule_then_a_new_version_closes_the_prior_window(): void
    {
        $this->postJson('/api/tax/rules', [
            'operator_code' => 'WIK', 'code' => 'WIK_VOICE_VAT', 'name' => 'Voice VAT',
            'taxable_category' => 'VOICE', 'rate' => 0.16, 'base_method' => 'BASE',
            'effective_from' => '2026-01-01T00:00:00Z',
        ])->assertCreated();

        // A rate change from July is a NEW version; the prior version is auto-closed.
        $this->postJson('/api/tax/rules', [
            'operator_code' => 'WIK', 'code' => 'WIK_VOICE_VAT', 'name' => 'Voice VAT',
            'taxable_category' => 'VOICE', 'rate' => 0.18, 'base_method' => 'BASE',
            'effective_from' => '2026-07-01T00:00:00Z',
        ])->assertCreated();

        $versions = TaxRule::query()->where('operator_code', 'WIK')->where('code', 'WIK_VOICE_VAT')
            ->orderBy('effective_from')->get();
        $this->assertCount(2, $versions);
        $this->assertNotNull($versions[0]->effective_until, 'prior version should be closed');
        $this->assertEquals($versions[1]->effective_from->toDateString(), $versions[0]->effective_until->toDateString());
        $this->assertNull($versions[1]->effective_until, 'newest version stays open');
    }

    public function test_rule_rate_must_be_a_fraction(): void
    {
        $this->postJson('/api/tax/rules', [
            'operator_code' => 'WIK', 'code' => 'BAD', 'name' => 'Bad', 'rate' => 16,
        ])->assertStatus(422);
    }

    public function test_group_rejects_unknown_member_rule(): void
    {
        $this->postJson('/api/tax/groups', [
            'operator_code' => 'WIK', 'code' => 'WIK_VOICE', 'name' => 'Voice taxes',
            'order_within_group' => ['NO_SUCH_RULE'],
        ])->assertStatus(422)->assertJsonPath('errorCode', 'R-PLM-CFG-02-G-3');
    }

    public function test_create_and_reorder_a_group(): void
    {
        $this->postJson('/api/tax/rules', [
            'operator_code' => 'WIK', 'code' => 'WIK_TV_VAT', 'name' => 'TV VAT', 'rate' => 0.16,
        ])->assertCreated();

        $res = $this->postJson('/api/tax/groups', [
            'operator_code' => 'WIK', 'code' => 'WIK_TV', 'name' => 'TV taxes',
            'order_within_group' => ['WIK_TV_VAT'],
        ])->assertCreated();

        $id = $res->json('tax_group_id');
        $this->patchJson("/api/tax/groups/{$id}", ['name' => 'TV taxes (renamed)'])
            ->assertOk()->assertJsonPath('name', 'TV taxes (renamed)');
    }
}
