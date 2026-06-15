<?php

namespace Modules\WorkOrder\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\WorkOrder\Database\Seeders\WoPocSeeder;
use Tests\TestCase;

/**
 * POC: the unauthenticated /api/poc/work-orders endpoint serves the seeded WO list enriched for
 * the YAS Dispatcher Console prototype (customer name + assignee label + computed SLA).
 */
class WoPocEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_poc_endpoint_returns_seeded_enriched_work_orders_without_auth(): void
    {
        $this->seed(WoPocSeeder::class);

        $res = $this->getJson('/api/poc/work-orders')->assertOk();
        $items = collect($res->json('items'));

        $this->assertGreaterThanOrEqual(11, $items->count());

        $wo = $items->firstWhere('woNumber', 'WO-841207');
        $this->assertNotNull($wo);
        $this->assertSame('Cheikh Ndiaye', $wo['customer']);                 // resolved customer name
        $this->assertStringContainsString('SenFiber', $wo['assignee']);      // contractor › team › tech label
        $this->assertStringContainsString('Sow', $wo['assignee']);
        $this->assertSame('FINALIZATION_PENDING', $wo['status']);
        $this->assertSame('RPT', $wo['jobType']);

        // A breached SLA renders red; a completed WO has no SLA.
        $breach = $items->firstWhere('woNumber', 'WO-841388');
        $this->assertSame('red', $breach['slaTone']);
        $completed = $items->firstWhere('woNumber', 'WO-840877');
        $this->assertSame('—', $completed['sla']);
    }
}
