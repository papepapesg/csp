<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\OperatorConfigSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\UiTranslationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * i18n resource catalog: studio-managed translations by culture/domain/section,
 * global vs operator scope, runtime merge for frontend + backend translator.
 */
class I18nResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(OperatorConfigSeeder::class);
        $this->seed(UiTranslationSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN'); // itops.manage
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_studio_crud_by_culture_domain_section_with_permission_gate(): void
    {
        $this->admin();

        // Catalog filtered by culture + domain + section.
        $this->getJson('/api/i18n/translations?locale=sw&domain=NAV&section=menu')->assertOk()
            ->assertJsonPath('items.0.locale', 'sw')
            ->assertJsonPath('items.0.domain', 'NAV');

        // Meta drives the studio pickers (cultures, domains, sections).
        $meta = $this->getJson('/api/i18n/meta')->assertOk();
        $this->assertContains('sw', $meta->json('locales'));
        $this->assertContains('NAV', $meta->json('domains'));
        $this->assertContains('menu', $meta->json('sections'));

        // Bulk upsert: a new culture (fr-SN) gets its own rows.
        $this->postJson('/api/i18n/translations', ['items' => [
            ['locale' => 'fr-SN', 'domain' => 'NAV', 'section' => 'menu', 'key' => 'Dashboard', 'value' => 'Tableau de bord (SN)'],
            ['locale' => 'fr-SN', 'domain' => 'COMMON', 'section' => 'actions', 'key' => 'Save', 'value' => 'Enregistrer'],
        ]])->assertCreated();
        $this->assertDatabaseHas('ui_translation', ['locale' => 'fr-SN', 'key' => 'Dashboard', 'value' => 'Tableau de bord (SN)']);

        // Upsert is idempotent on the (scope, culture, domain, section, key) address.
        $this->postJson('/api/i18n/translations', ['items' => [
            ['locale' => 'fr-SN', 'domain' => 'NAV', 'section' => 'menu', 'key' => 'Dashboard', 'value' => 'Tableau de bord'],
        ]])->assertCreated();
        $this->assertSame(1, \App\Foundation\Models\UiTranslation::query()->where(['locale' => 'fr-SN', 'key' => 'Dashboard'])->count());

        // Delete removes the row.
        $id = \App\Foundation\Models\UiTranslation::query()->where(['locale' => 'fr-SN', 'key' => 'Save'])->value('id');
        $this->deleteJson("/api/i18n/translations/{$id}")->assertOk();
        $this->assertDatabaseMissing('ui_translation', ['id' => $id]);

        // Writes are gated: a care agent can read but not edit.
        $agent = User::factory()->create(['operator_code' => 'WIK']);
        $agent->assignRole('CUSTOMER_CARE_AGENT');
        Sanctum::actingAs($agent);
        $this->getJson('/api/i18n/translations?locale=sw')->assertOk();
        $this->postJson('/api/i18n/translations', ['items' => [
            ['locale' => 'sw', 'key' => 'X', 'value' => 'Y'],
        ]])->assertForbidden();
    }

    public function test_operator_override_wins_over_global_in_the_merged_resources(): void
    {
        $this->admin();

        // Global says 'Wateja'; WIK overrides the same key for its own deployment.
        $this->postJson('/api/i18n/translations', ['items' => [
            ['locale' => 'sw', 'domain' => 'NAV', 'section' => 'menu', 'key' => 'Customers', 'value' => 'Wateja wa WIK', 'operator_code' => 'WIK'],
        ]])->assertCreated();

        $merged = $this->getJson('/api/i18n/resources?locale=sw')->assertOk();
        $this->assertSame('Wateja wa WIK', $merged->json('resources.Customers')); // WIK override
        $this->assertSame('Tiketi', $merged->json('resources.Tickets'));          // global row untouched
    }

    public function test_backend_translator_honours_studio_resources_per_request(): void
    {
        $this->admin();

        // Studio edits the Swahili backend string — no deploy, no lang-file change.
        $this->postJson('/api/i18n/translations', ['items' => [
            ['locale' => 'sw', 'domain' => 'BACKEND', 'section' => 'api', 'key' => 'Not found', 'value' => 'Haipo kabisa'],
        ]])->assertCreated();

        // A request in that culture runs the translator with the catalog merged in.
        $this->getJson('/api/i18n/resources?locale=sw', ['X-Locale' => 'sw'])
            ->assertOk()->assertHeader('Content-Language', 'sw');
        $this->assertSame('Haipo kabisa', __('Not found'));
    }
}
