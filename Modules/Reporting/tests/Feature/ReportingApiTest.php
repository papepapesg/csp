<?php

namespace Modules\Reporting\Tests\Feature;

use App\Foundation\Events\Outbox\OutboxEvent;
use App\Foundation\Events\OutboxEventPublished;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Reporting\Models\ReportDailyMetric;
use Modules\Reporting\Projectors\ReportMetricProjector;
use Tests\TestCase;

class ReportingApiTest extends TestCase
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

    public function test_dashboard_projects_metrics_from_outbox_events(): void
    {
        // Generate operational facts.
        $this->postJson('/api/subscriptions', [
            'customer_id' => 'c1', 'account_id' => 'a1', 'homepass_id' => 'h1', 'package_ref' => 'p1',
        ])->assertCreated();
        $this->postJson('/api/invoices', [
            'account_id' => 'a1', 'lines' => [['description' => 'Fiber', 'unit_price' => 4999]],
        ])->assertCreated();
        $this->postJson('/api/payments', [
            'account_id' => 'a1', 'paid_amount' => 4999, 'method' => 'MPESA',
        ], ['Idempotency-Key' => 'p1'])->assertCreated();

        // Project committed outbox events into the reporting mart.
        Artisan::call('sophix:outbox:dispatch');

        $ops = $this->getJson('/api/reports/dashboards/operations-overview')->assertOk()->json('metrics');
        $this->assertEquals(1, $ops['subscriptions_created']);

        $rev = $this->getJson('/api/reports/dashboards/revenue-overview')->assertOk()->json('metrics');
        $this->assertEquals(1, $rev['invoices_generated']);
        $this->assertEquals(4999, $rev['payments_amount']);
    }

    public function test_projection_is_idempotent_on_redispatch(): void
    {
        $this->postJson('/api/subscriptions', [
            'customer_id' => 'c2', 'account_id' => 'a2', 'homepass_id' => 'h2', 'package_ref' => 'p2',
        ])->assertCreated();

        Artisan::call('sophix:outbox:dispatch');
        // Re-fire the same events through the projector; inbox dedupe must hold counts.
        $projector = app(ReportMetricProjector::class);
        foreach (OutboxEvent::all() as $row) {
            $projector->handle(new OutboxEventPublished($row));
        }

        $this->assertSame('1.00', (string) ReportDailyMetric::where('metric_key', 'subscriptions_created')->first()->value);
    }

    public function test_unknown_dashboard_returns_404(): void
    {
        $this->getJson('/api/reports/dashboards/does-not-exist')->assertStatus(404);
    }

    public function test_requires_report_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('FIELD_TECHNICIAN');
        Sanctum::actingAs($user);

        $this->getJson('/api/reports/dashboards/operations-overview')->assertForbidden();
    }
}
