<?php

namespace Modules\Billing\Tax\Database\Seeders;

use App\Foundation\Approvals\ApprovalDefinition;
use Illuminate\Database\Seeder;
use Modules\Billing\Tax\Models\TaxInvoice;
use Modules\Billing\Tax\Models\TaxOperatorConfig;

/**
 * BIL-02-TAX-01 per-operator enablement. Enables tax invoicing for the default operator with
 * the stub signer; other operators (e.g. those without a tax authority) leave enabled=false and
 * tax invoices are simply not generated (T-4). Also authors the C-1 signed-cancellation approval
 * process on the EM-CFG-04 engine — dual control is definition-first, not hand-rolled.
 */
class TaxConfigSeeder extends Seeder
{
    public function run(): void
    {
        $operator = config('sophix.default_operator', 'WIK');

        TaxOperatorConfig::query()->updateOrCreate(
            ['operator_code' => $operator],
            ['enabled' => true, 'signing_service_implementation_ref' => 'stub', 'signing_timeout_seconds' => 30, 'legal_number_format' => 'TAX-{operator}-{year}-{seq}'],
        );

        // C-1: cancelling a SIGNED tax invoice needs one approval by someone OTHER than the
        // requester (allow_requester=false). Roles are open — the tax.compliance route permission
        // gates WHO may approve; the engine enforces the distinct-approver rule.
        ApprovalDefinition::defineChain($operator, TaxInvoice::ENTITY_TYPE, TaxInvoice::ACTION_CANCELLATION, [
            ['name' => 'Signed tax-invoice cancellation', 'approver_kind' => 'ROLE', 'approver_roles' => [], 'required_approvals' => 1, 'allow_requester' => false],
        ]);
    }
}
