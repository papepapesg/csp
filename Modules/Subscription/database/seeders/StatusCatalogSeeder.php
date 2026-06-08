<?php

namespace Modules\Subscription\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Subscription\Models\SubscriptionStatusCode;
use Modules\Subscription\Models\SubscriptionTransitionReason;

/**
 * Seeds the SUB-LM-01 §5.2/5.3 lifecycle catalogs: the authoritative status
 * vocabulary (incl. the per-operation transient PENDING_* states) and the
 * transition-reason codes. status_code on the subscription master must reference
 * a row here.
 */
class StatusCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // code, display, [is_active,is_billable,is_terminal,is_pending,allows_pkg,allows_move]
        $statuses = [
            ['CREATED', 'Created', false, false, false, false, false, false],
            ['PENDING_ACTIVATION', 'Pending activation', false, false, false, true, false, false],
            ['ACTIVE', 'Active', true, true, false, false, true, true],
            ['PENDING_PAUSE', 'Pending pause', false, false, false, true, false, false],
            ['PENDING_RESUME', 'Pending resume', false, false, false, true, false, false],
            ['PENDING_SUSPEND_NP', 'Pending non-payment suspension', false, false, false, true, false, false],
            ['SUSPENDED', 'Suspended', false, true, false, false, false, false],
            ['PENDING_UPGRADE', 'Pending upgrade', true, true, false, true, false, false],
            ['PENDING_DOWNGRADE', 'Pending downgrade', true, true, false, true, false, false],
            ['PENDING_RELOCATION', 'Pending relocation', true, true, false, true, false, false],
            ['PENDING_MIGRATION', 'Pending migration', true, true, false, true, false, false],
            ['PENDING_TERMINATION', 'Pending termination', false, false, false, true, false, false],
            ['TERMINATED', 'Terminated', false, false, true, false, false, false],
            ['RETIRED', 'Retired', false, false, true, false, false, false],
        ];
        foreach ($statuses as [$code, $name, $active, $billable, $terminal, $pending, $pkg, $move]) {
            SubscriptionStatusCode::query()->updateOrCreate(['code' => $code], [
                'display_name' => $name, 'is_active' => $active, 'is_billable' => $billable,
                'is_terminal' => $terminal, 'is_pending' => $pending,
                'allows_package_change' => $pkg, 'allows_address_move' => $move, 'active' => true,
            ]);
        }

        // reason_code, transition_type, display
        $reasons = [
            ['CUSTOMER_REQUESTED_PAUSE', 'PAUSE', 'Customer requested pause'],
            ['ADMIN_PAUSE', 'PAUSE', 'Admin pause'],
            ['CUSTOMER_TEMPORARY_AWAY', 'PAUSE', 'Customer temporarily away'],
            ['CUSTOMER_REQUESTED_RESUME', 'RESUME', 'Customer requested resume'],
            ['DUNNING_RESUME', 'RESUME', 'Resume after debt cleared (dunning)'],
            ['ADMIN_FORCE_RESUME', 'RESUME', 'Admin force resume'],
            ['DUNNING_LEVEL_3_NON_PAYMENT', 'SUSPEND_NP', 'Non-payment suspension (dunning L3)'],
            ['CUSTOMER_REQUESTED_UPGRADE', 'UPGRADE', 'Customer requested upgrade'],
            ['ADMIN_UPGRADE', 'UPGRADE', 'Admin upgrade'],
            ['CUSTOMER_REQUESTED_DOWNGRADE', 'DOWNGRADE', 'Customer requested downgrade'],
            ['CUSTOMER_REQUESTED_RELOCATION', 'RELOCATION', 'Customer requested relocation'],
            ['CUSTOMER_REQUESTED_MIGRATION', 'MIGRATION', 'Customer requested migration'],
            ['CUSTOMER_MOVED_NO_COVERAGE', 'TERMINATE', 'Customer moved outside coverage'],
            ['CONTRACT_EXPIRED', 'TERMINATE', 'Contract expired'],
            ['CUSTOMER_REQUESTED_TERMINATION', 'TERMINATE', 'Customer requested termination'],
        ];
        foreach ($reasons as [$code, $type, $name]) {
            SubscriptionTransitionReason::query()->updateOrCreate(['reason_code' => $code], [
                'transition_type' => $type, 'display_name' => $name, 'active' => true,
            ]);
        }
    }
}
