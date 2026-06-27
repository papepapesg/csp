<?php

namespace Modules\Catalog\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Catalog\Database\Seeders\TaxCatalogSeeder;
use Modules\Catalog\Tax\Services\TaxComputeService;
use Tests\TestCase;

class TaxComputeTest extends TestCase
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

    public function test_cascading_tax_matches_dd_worked_example(): void
    {
        // DD worked example: base 10000 -> excise 1500 (BASE) + VAT 1840 (BASE_PLUS_PRIOR) = 3340.
        $result = app(TaxComputeService::class)->compute([
            'operatorCode' => 'WIK', 'taxableKind' => 'PACKAGE', 'baseAmount' => 10000, 'currency' => 'KES',
        ]);

        $this->assertSame('RESOLVED', $result['resolutionStatus']);
        $this->assertEqualsWithDelta(1500.0, $result['taxLines'][0]['taxAmount'], 0.001);
        $this->assertEqualsWithDelta(1840.0, $result['taxLines'][1]['taxAmount'], 0.001);
        $this->assertEqualsWithDelta(3340.0, $result['totalTaxAmount'], 0.001);
        $this->assertEqualsWithDelta(13340.0, $result['totalWithTax'], 0.001);
    }

    public function test_unresolved_kind_returns_zero_tax(): void
    {
        $result = app(TaxComputeService::class)->compute([
            'operatorCode' => 'WIK', 'taxableKind' => 'WALLET_TOPUP', 'baseAmount' => 500,
        ]);
        $this->assertSame('NO_TAX_GROUP_RESOLVED', $result['resolutionStatus']);
        $this->assertSame(0.0, $result['totalTaxAmount']);
    }

    public function test_falls_back_to_product_default_tax_group_when_rule_resolves_nothing(): void
    {
        // The applicability rule only covers PACKAGE/INTERNET; a SERVICE resolves to null →
        // compute falls back to the service's own default_tax_group_ref (WIK_INTERNET).
        $class = \Modules\Catalog\Plm\Models\ServiceClass::query()->create([
            'id' => 'scls_bb', 'operator_code' => 'WIK', 'name' => 'Broadband',
        ]);
        \Modules\Catalog\Plm\Models\Service::query()->create([
            'id' => 'svc_100', 'operator_code' => 'WIK', 'code' => 'FTTH-100', 'name' => 'FTTH 100',
            'service_class_id' => $class->id, 'default_tax_group_ref' => 'WIK_INTERNET',
        ]);

        $result = app(TaxComputeService::class)->compute([
            'operatorCode' => 'WIK', 'taxableKind' => 'SERVICE', 'taxableRef' => 'svc_100',
            'baseAmount' => 10000, 'currency' => 'KES',
        ]);

        $this->assertSame('RESOLVED', $result['resolutionStatus']);
        $this->assertSame('WIK_INTERNET', $result['taxGroup']);
        $this->assertEqualsWithDelta(3340.0, $result['totalTaxAmount'], 0.001); // same cascade as the worked example
    }

    public function test_tax_compute_endpoint(): void
    {
        $this->postJson('/api/tax/compute', ['operatorCode' => 'WIK', 'taxableKind' => 'PACKAGE', 'baseAmount' => 10000])
            ->assertOk()->assertJsonPath('totalTaxAmount', 3340);
    }
}
