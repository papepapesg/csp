<?php

namespace Modules\Reporting\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Reporting\Models\ReportDailyMetric;
use Tests\TestCase;

class ReportExportReconcileTest extends TestCase
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

    private function generateAndProject(): void
    {
        $this->postJson('/api/subscriptions', [
            'customer_id' => 'c1', 'account_id' => 'a1', 'homepass_id' => 'h1', 'package_ref' => 'p1',
        ])->assertCreated();
        $this->postJson('/api/invoices', [
            'account_id' => 'a1', 'lines' => [['description' => 'Fiber', 'unit_price' => 4999]],
        ])->assertCreated();
        Artisan::call('sophix:outbox:dispatch');
    }

    public function test_csv_export_returns_mart_rows(): void
    {
        $this->generateAndProject();

        $res = $this->get('/api/reports/export/revenue-overview');
        $res->assertOk();
        $res->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $body = $res->getContent();
        $this->assertStringContainsString('metric_key', $body);
        $this->assertStringContainsString('invoices_generated', $body);
    }

    public function test_reconcile_in_sync_when_mart_matches_events(): void
    {
        $this->generateAndProject();

        $this->getJson('/api/reports/reconcile')
            ->assertOk()
            ->assertJsonPath('inSync', true)
            ->assertJsonPath('discrepancies', []);
    }

    public function test_reconcile_detects_mart_drift(): void
    {
        $this->generateAndProject();

        // Corrupt the mart to simulate a missed/double-counted projection.
        ReportDailyMetric::query()->where('metric_key', 'invoices_generated')
            ->update(['value' => 99]);

        $res = $this->getJson('/api/reports/reconcile')->assertOk()->assertJsonPath('inSync', false);
        $disc = collect($res->json('discrepancies'))->firstWhere('metric', 'invoices_generated');
        $this->assertNotNull($disc);
        $this->assertEquals(99, $disc['actual']);
        $this->assertEquals(1, $disc['expected']);
    }
}
