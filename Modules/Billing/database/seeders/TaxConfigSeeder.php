<?php

namespace Modules\Billing\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Billing\Tax\Models\TaxOperatorConfig;

/**
 * BIL-02-TAX-01 per-operator enablement. Enables tax invoicing for the default operator with
 * the stub signer; other operators (e.g. those without a tax authority) leave enabled=false and
 * tax invoices are simply not generated (T-4).
 */
class TaxConfigSeeder extends Seeder
{
    public function run(): void
    {
        TaxOperatorConfig::query()->updateOrCreate(
            ['operator_code' => config('sophix.default_operator', 'WIK')],
            ['enabled' => true, 'signing_service_implementation_ref' => 'stub', 'signing_timeout_seconds' => 30, 'legal_number_format' => 'TAX-{operator}-{year}-{seq}'],
        );
    }
}
