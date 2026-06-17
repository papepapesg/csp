<?php

namespace Modules\Ticketing\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Ticketing\Models\TicketCategory;

/** TCK-01 §7.6 WIK category catalog seed (routing + WO-allowed gating). */
class TicketCategorySeeder extends Seeder
{
    public function run(): void
    {
        $operator = config('sophix.default_operator', 'WIK');
        // [category, display, type, priority, queue, asr_type, wo_allowed, wo_kind]
        $rows = [
            ['TECHNICAL', 'Technical support', 'TECHNICAL_SUPPORT', 'HIGH', 'TECH_SUPPORT_L1', 'TECHNICAL_TROUBLE', true, 'SUPPORT'],
            ['NO_INTERNET', 'No internet', 'TECHNICAL_SUPPORT', 'HIGH', 'TECH_SUPPORT_L1', 'TECHNICAL_TROUBLE', true, 'SUPPORT'],
            ['TV_ISSUE', 'TV / signal issue', 'TECHNICAL_SUPPORT', 'NORMAL', 'TECH_SUPPORT_L1', 'TECHNICAL_TROUBLE', true, 'SUPPORT'],
            ['BILLING_DISPUTE', 'Billing dispute', 'BILLING_COMPLAINT', 'NORMAL', 'BILLING_QUEUE', 'COMPLAINT', false, null],
            ['INSTALL_INCOMPLETE', 'Install incomplete', 'INSTALLATION_FOLLOWUP', 'HIGH', 'TECH_SUPPORT_L1', 'SERVICE_REQUEST', true, 'SUPPORT'],
            ['GENERAL_INQUIRY', 'General inquiry', 'GENERAL_INQUIRY', 'LOW', 'CARE_QUEUE', 'INFORMATION_REQUEST', false, null],
            ['KYC_SUPPORT', 'KYC support', 'KYC_SUPPORT', 'NORMAL', 'CARE_QUEUE', 'SERVICE_REQUEST', false, null],
        ];
        foreach ($rows as [$cat, $name, $type, $prio, $queue, $asrType, $woAllowed, $woKind]) {
            TicketCategory::query()->updateOrCreate(
                ['operator_code' => $operator, 'category_code' => $cat],
                ['display_name' => $name, 'type_code' => $type, 'default_priority' => $prio,
                 'default_queue' => $queue, 'default_asr_type' => $asrType,
                 'wo_allowed' => $woAllowed, 'default_wo_kind' => $woKind, 'active' => true],
            );
        }
    }
}
