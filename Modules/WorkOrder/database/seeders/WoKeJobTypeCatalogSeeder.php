<?php

namespace Modules\WorkOrder\Database\Seeders;

use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Modules\WorkOrder\Models\WoJobTypeCatalog;

/**
 * Wananchi/Zuku KE Job-Type catalog — seeded VERBATIM from the WO workshop GAP doc §8.1
 * ("Full Job Type catalog · ~50 distinct codes · 10 categories", Bildad Gitonga session 2026-04-30).
 *
 * Faithfulness rules (only what the workshop states):
 *   - job_type_code  : verbatim from §8.1.
 *   - description    : "<Group> — <group purpose>" copied from §8.1 (the purpose is given per GROUP,
 *                      not per code, so it is attached at group level).
 *   - display_name   : a phrase from the group purpose where the §8.1 ordering maps a code 1:1
 *                      (Installations, Equipment pickup/recovery/upgrade, Disconnections, QC&Audit);
 *                      otherwise the code itself (the workshop does not name every code).
 *   - warranty_days  : ONLY Support = 90 (3 months) and Installation = 180 (6 months) are stated
 *                      (§8.6); other groups left at 90 (table default) — NOT workshop-asserted.
 *   - network_type   : set ONLY where explicit — VIP-HFC/VIP-GPON, "HFC install"/"rare HFC add-internet",
 *                      and "shifting per network" (HS*=HFC, GS*=GPON). Migration HFC<->GPON left null.
 *   - requires_site_visit : GSD = "No Site Visit · Work Units 0" => false (§3); all others default true.
 *   - kind           : INSTALLATION / SUPPORT / SHIFTING flow grouping (per group semantics).
 *
 * Run after WoSupportSeeder; updateOrCreate so the verbatim KE rows are authoritative.
 */
class WoKeJobTypeCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $operator = config('sophix.default_operator', 'WIK');

        // [code, kind, network, requires_site_visit, warranty_days, display_name, group+purpose description]
        $rows = [
            // Address & Account — "Address change/disconnect, inactivate"
            ['ACD', 'SUPPORT', null, true, 90, 'Address change/disconnect', 'Address & Account — address change/disconnect, inactivate'],
            ['INA', 'SUPPORT', null, true, 90, 'Inactivate', 'Address & Account — address change/disconnect, inactivate'],

            // Service Changes — "Package change"
            ['COS', 'SUPPORT', null, true, 90, 'Package change', 'Service Changes — package change'],

            // Installations — "HFC install, VOIP install, paid+free Wi-Fi extender installs, rescheduled TV install"
            ['H01', 'INSTALLATION', 'HFC', true, 180, 'HFC install', 'Installations — HFC install, VOIP install, paid+free Wi-Fi extender installs, rescheduled TV install'],
            ['G07', 'INSTALLATION', null, true, 180, 'VOIP install', 'Installations — HFC install, VOIP install, paid+free Wi-Fi extender installs, rescheduled TV install'],
            ['DLC', 'INSTALLATION', null, true, 180, 'Paid Wi-Fi extender install', 'Installations — HFC install, VOIP install, paid+free Wi-Fi extender installs, rescheduled TV install'],
            ['DL4', 'INSTALLATION', null, true, 180, 'Free Wi-Fi extender install', 'Installations — HFC install, VOIP install, paid+free Wi-Fi extender installs, rescheduled TV install'],
            ['RTV', 'INSTALLATION', null, true, 180, 'Rescheduled TV install', 'Installations — HFC install, VOIP install, paid+free Wi-Fi extender installs, rescheduled TV install'],

            // Support / Service Calls — "Per-network/service support routing" (QRS replaces PS0/PS1)
            ['GP3', 'SUPPORT', null, true, 90, 'GP3', 'Support / Service Calls — per-network/service support routing'],
            ['GP4', 'SUPPORT', null, true, 90, 'GP4', 'Support / Service Calls — per-network/service support routing'],
            ['HS1', 'SUPPORT', null, true, 90, 'HS1', 'Support / Service Calls — per-network/service support routing'],
            ['HS2', 'SUPPORT', null, true, 90, 'HS2', 'Support / Service Calls — per-network/service support routing'],
            ['HS3', 'SUPPORT', null, true, 90, 'HS3', 'Support / Service Calls — per-network/service support routing'],
            ['HS4', 'SUPPORT', null, true, 90, 'HS4', 'Support / Service Calls — per-network/service support routing'],
            ['DLC2', 'SUPPORT', null, true, 90, 'DLC2', 'Support / Service Calls — per-network/service support routing'],
            ['SRV', 'SUPPORT', null, true, 90, 'SRV', 'Support / Service Calls — per-network/service support routing'],
            ['QRS', 'SUPPORT', null, true, 90, 'QRS (replaces PS0/PS1)', 'Support / Service Calls — per-network/service support routing'],
            ['WCS', 'SUPPORT', null, true, 90, 'WCS', 'Support / Service Calls — per-network/service support routing'],
            ['VIP-HFC', 'SUPPORT', 'HFC', true, 90, 'VIP support (HFC)', 'Support / Service Calls — per-network/service support routing'],
            ['VIP-GPON', 'SUPPORT', 'GPON', true, 90, 'VIP support (GPON)', 'Support / Service Calls — per-network/service support routing'],
            ['ZOI', 'SUPPORT', null, true, 90, 'ZOI', 'Support / Service Calls — per-network/service support routing'],
            ['ZOT', 'SUPPORT', null, true, 90, 'ZOT', 'Support / Service Calls — per-network/service support routing'],

            // Equipment — "Pickup, recovery, upgrade, decoder/handset/modem"
            ['EQP', 'SUPPORT', null, true, 90, 'Equipment pickup', 'Equipment — pickup, recovery, upgrade, decoder/handset/modem'],
            ['EQ1', 'SUPPORT', null, true, 90, 'EQ1', 'Equipment — pickup, recovery, upgrade, decoder/handset/modem'],
            ['EQR', 'SUPPORT', null, true, 90, 'Equipment recovery', 'Equipment — pickup, recovery, upgrade, decoder/handset/modem'],
            ['EQU', 'SUPPORT', null, true, 90, 'Equipment upgrade', 'Equipment — pickup, recovery, upgrade, decoder/handset/modem'],
            ['PH1', 'SUPPORT', null, true, 90, 'PH1', 'Equipment — pickup, recovery, upgrade, decoder/handset/modem'],
            ['GP2', 'SUPPORT', null, true, 90, 'GP2', 'Equipment — pickup, recovery, upgrade, decoder/handset/modem'],
            ['H05', 'SUPPORT', null, true, 90, 'H05', 'Equipment — pickup, recovery, upgrade, decoder/handset/modem'],
            ['H07', 'SUPPORT', null, true, 90, 'H07', 'Equipment — pickup, recovery, upgrade, decoder/handset/modem'],
            ['WMQ', 'SUPPORT', null, true, 90, 'WMQ', 'Equipment — pickup, recovery, upgrade, decoder/handset/modem'],

            // Disconnections & Relocations — "Drop disconnect, shifting per network, contractor billing pair, Zuku Office shift"
            ['DDW', 'SHIFTING', null, true, 90, 'Drop disconnect', 'Disconnections & Relocations — drop disconnect, shifting per network, contractor billing pair, Zuku Office shift'],
            ['HSD', 'SHIFTING', 'HFC', true, 90, 'HFC shifting disconnect', 'Disconnections & Relocations — drop disconnect, shifting per network, contractor billing pair, Zuku Office shift'],
            ['HSR', 'SHIFTING', 'HFC', true, 90, 'HFC shifting reconnect', 'Disconnections & Relocations — drop disconnect, shifting per network, contractor billing pair, Zuku Office shift'],
            ['GSD', 'SHIFTING', 'GPON', false, 90, 'GPON shifting disconnect (No Site Visit)', 'Disconnections & Relocations — drop disconnect, shifting per network, contractor billing pair, Zuku Office shift'],
            ['GSR', 'SHIFTING', 'GPON', true, 90, 'GPON shifting reconnect', 'Disconnections & Relocations — drop disconnect, shifting per network, contractor billing pair, Zuku Office shift'],
            ['TRD', 'SHIFTING', null, true, 90, 'Contractor billing pair (TRD)', 'Disconnections & Relocations — drop disconnect, shifting per network, contractor billing pair, Zuku Office shift'],
            ['TRC', 'SHIFTING', null, true, 90, 'Contractor billing pair (TRC)', 'Disconnections & Relocations — drop disconnect, shifting per network, contractor billing pair, Zuku Office shift'],
            ['ZOD', 'SHIFTING', null, true, 90, 'Zuku Office shift', 'Disconnections & Relocations — drop disconnect, shifting per network, contractor billing pair, Zuku Office shift'],

            // Network Migration — "HFC<->GPON migration disconnect/reconnect"
            ['GPD', 'SHIFTING', null, true, 90, 'Migration (GPD)', 'Network Migration — HFC<->GPON migration disconnect/reconnect'],
            ['HMD', 'SHIFTING', null, true, 90, 'Migration (HMD)', 'Network Migration — HFC<->GPON migration disconnect/reconnect'],
            ['HSM', 'SHIFTING', null, true, 90, 'Migration (HSM)', 'Network Migration — HFC<->GPON migration disconnect/reconnect'],

            // Quality Control & Audit — "Post-install audit, NOC escalation, repeat-WO within warranty"
            ['QCA', 'SUPPORT', null, true, 90, 'Post-install audit', 'Quality Control & Audit — post-install audit, NOC escalation, repeat-WO within warranty'],
            ['QCS', 'SUPPORT', null, true, 90, 'NOC escalation', 'Quality Control & Audit — post-install audit, NOC escalation, repeat-WO within warranty'],
            ['RPT', 'SUPPORT', null, false, 90, 'Repeat-WO within warranty', 'Quality Control & Audit — post-install audit, NOC escalation, repeat-WO within warranty'],

            // Contractor Invoicing / Penalties — "Support / install penalties (penalty = 2x standard pay)"
            ['SDI', 'SUPPORT', null, true, 90, 'Install penalty', 'Contractor Invoicing / Penalties — support/install penalties (penalty = 2x standard pay)'],
            ['SDS', 'SUPPORT', null, true, 90, 'Support penalty', 'Contractor Invoicing / Penalties — support/install penalties (penalty = 2x standard pay)'],

            // Retention / Win-Back — "Churn win-back"
            ['SMU', 'SUPPORT', null, true, 90, 'Churn win-back', 'Retention / Win-Back — churn win-back'],

            // Other / Specialised — "Right-of-entry, line maint, B2B Symbinet, rare HFC add-internet"
            ['ROE', 'SUPPORT', null, true, 90, 'Right-of-entry', 'Other / Specialised — right-of-entry, line maintenance, B2B Symbinet, rare HFC add-internet'],
            ['LNM', 'SUPPORT', null, true, 90, 'Line maintenance', 'Other / Specialised — right-of-entry, line maintenance, B2B Symbinet, rare HFC add-internet'],
            ['WQC', 'SUPPORT', null, true, 90, 'WQC', 'Other / Specialised — right-of-entry, line maintenance, B2B Symbinet, rare HFC add-internet'],
            ['SMN', 'SUPPORT', null, true, 90, 'B2B Symbinet', 'Other / Specialised — right-of-entry, line maintenance, B2B Symbinet, rare HFC add-internet'],
            ['H06', 'SUPPORT', 'HFC', true, 90, 'Rare HFC add-internet', 'Other / Specialised — right-of-entry, line maintenance, B2B Symbinet, rare HFC add-internet'],
        ];

        foreach ($rows as [$code, $kind, $net, $visit, $warranty, $name, $desc]) {
            WoJobTypeCatalog::query()->updateOrCreate(
                ['operator_code' => $operator, 'job_type_code' => $code],
                [
                    'id' => Id::make('wojt'),
                    'kind' => $kind,
                    'display_name' => $name,
                    'description' => $desc,
                    'network_type' => $net,
                    'requires_site_visit' => $visit,
                    'warranty_days' => $warranty,
                ],
            );
        }
    }
}
