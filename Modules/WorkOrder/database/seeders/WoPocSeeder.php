<?php

namespace Modules\WorkOrder\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * POC seed data for the YAS Work Order Dispatcher Console prototype. Populates a realistic,
 * DB-sourced graph (contractors → teams → technicians, customers, and work orders using the KE
 * job-type codes) so the prototype renders from seeded data via the (unauthenticated) POC
 * endpoint. Idempotent. Operator = the deployment default.
 */
class WoPocSeeder extends Seeder
{
    public function run(): void
    {
        $op = config('sophix.default_operator', 'WIK');
        $now = now();
        $t = ['created_at' => $now, 'updated_at' => $now];

        $contractors = [
            ['ctr_senfiber', 'SenFiber Network Services'],
            ['ctr_netcorp', 'NetCorp'],
            ['ctr_fiberplus', 'FiberPlus'],
        ];
        foreach ($contractors as [$id, $name]) {
            DB::table('contractor')->updateOrInsert(['contractor_id' => $id], [
                'operator_code' => $op, 'code' => strtoupper(substr($id, 4, 3)), 'name' => $name,
                'type' => 'COMPANY', 'skills' => json_encode(['GPON', 'HFC']), 'status' => 'ACTIVE',
            ] + $t);
        }

        $teams = [
            ['team_a', 'ctr_senfiber', 'Team A'],
            ['team_b', 'ctr_senfiber', 'Team B — North Drop Crew'],
            ['team_c', 'ctr_fiberplus', 'Team C'],
            ['team_d', 'ctr_netcorp', 'Team D'],
            ['team_core', 'ctr_netcorp', 'Core'],
            ['team_fb_b', 'ctr_fiberplus', 'Team B'],
        ];
        foreach ($teams as [$id, $ctr, $name]) {
            DB::table('contractor_team')->updateOrInsert(['team_id' => $id], [
                'contractor_id' => $ctr, 'operator_code' => $op, 'code' => strtoupper($id),
                'name' => $name, 'skills' => json_encode(['GPON', 'HFC']), 'status' => 'ACTIVE',
            ] + $t);
        }

        $techs = [
            ['stf_ifall', 'Ibrahima Fall', 'ctr_senfiber', 'team_a'],
            ['stf_msow', 'Mamadou Sow', 'ctr_senfiber', 'team_b'],
            ['stf_odiouf', 'Ousmane Diouf', 'ctr_fiberplus', 'team_c'],
            ['stf_ksy', 'Khadija Sy', 'ctr_netcorp', 'team_d'],
            ['stf_aba', 'Aliou Ba', 'ctr_netcorp', 'team_core'],
            ['stf_rcisse', 'Rokhaya Cissé', 'ctr_fiberplus', 'team_fb_b'],
        ];
        foreach ($techs as [$id, $name, $ctr, $team]) {
            DB::table('staff_member')->updateOrInsert(['staff_id' => $id], [
                'operator_code' => $op, 'contractor_id' => $ctr, 'team_id' => $team, 'name' => $name,
                'role' => 'TECHNICIAN', 'msisdn' => '+221770000000', 'skills' => json_encode(['GPON', 'FIBER']),
                'status' => 'ACTIVE',
            ] + $t);
        }

        // work orders: [num, type, kind, jobType, customerName, region, contractor, team, tech, priority, status, slaMin|null, schedMinFromMidnight, schedDayOffset]
        $wos = [
            ['WO-841320', 'INSTALLATION', 'INSTALLATION', 'GP3', 'Awa Diop', 'Dakar-Plateau', 'ctr_senfiber', 'team_a', 'stf_ifall', 'NORMAL', 'ASSIGNED', 252, 960, 0],
            ['WO-841207', 'SUPPORT', 'SUPPORT', 'RPT', 'Cheikh Ndiaye', 'Pikine', 'ctr_senfiber', 'team_b', 'stf_msow', 'HIGH', 'FINALIZATION_PENDING', 65, 870, 0],
            ['WO-840988', 'NOC', 'SUPPORT', 'QCS', 'Sonatel Business', 'Guédiawaye', 'ctr_netcorp', 'team_core', 'stf_aba', 'URGENT', 'PENDING', -10, 555, 0],
            ['WO-841412', 'EQUIPMENT', 'SUPPORT', 'GSD', 'Fatou Sarr', 'Rufisque', 'ctr_fiberplus', 'team_c', 'stf_odiouf', 'LOW', 'PENDING', 400, 510, 1],
            ['WO-841155', 'SHIFTING', 'SHIFTING', 'HS1', 'Mamadou Gueye', 'Thiès', 'ctr_senfiber', 'team_a', 'stf_ifall', 'NORMAL', 'IN_PROGRESS', 200, 945, 0],
            ['WO-841299', 'INSTALLATION', 'INSTALLATION', 'GP3', 'Ndeye Faye', 'Dakar-Plateau', 'ctr_netcorp', 'team_d', 'stf_ksy', 'HIGH', 'ASSIGNED', 108, 1030, 0],
            ['WO-840877', 'SUPPORT', 'SUPPORT', 'GSR', 'Ibrahima Diallo', 'Pikine', 'ctr_fiberplus', 'team_fb_b', 'stf_rcisse', 'NORMAL', 'COMPLETED', null, 660, 0],
            ['WO-841501', 'NOC', 'SUPPORT', 'QCS', 'Teranga Foods', 'Guédiawaye', 'ctr_netcorp', 'team_core', 'stf_aba', 'URGENT', 'IN_PROGRESS', 55, 800, 0],
            ['WO-841033', 'EQUIPMENT', 'SUPPORT', 'GSD', 'Aïssatou Ndour', 'Rufisque', 'ctr_senfiber', 'team_c', 'stf_msow', 'LOW', 'CANCELLED', null, 600, -1],
            ['WO-841388', 'SHIFTING', 'SHIFTING', 'HS1', 'Moussa Camara', 'Thiès', 'ctr_fiberplus', 'team_a', 'stf_odiouf', 'NORMAL', 'PENDING', -40, 640, 0],
            ['WO-841244', 'INSTALLATION', 'INSTALLATION', 'GP3', 'Khadija Mbaye', 'Dakar-Plateau', 'ctr_senfiber', 'team_b', 'stf_ifall', 'HIGH', 'FINALIZATION_PENDING', 150, 1080, 0],
        ];

        $custSeq = 1;
        foreach ($wos as [$num, $type, $kind, $job, $cust, $region, $ctr, $team, $tech, $prio, $status, $slaMin, $schedMin, $dayOff]) {
            $custId = 'cust_poc_'.$custSeq++;
            DB::table('customer')->updateOrInsert(['customer_id' => $custId], [
                'operator_code' => $op, 'type' => 'RES', 'name' => $cust,
                'primary_msisdn' => '+22177'.str_pad((string) $custSeq, 7, '0', STR_PAD_LEFT),
                'preferred_language' => 'fr', 'kyc_status' => 'APPROVED',
            ] + $t);

            $sched = now()->startOfDay()->addDays($dayOff)->addMinutes($schedMin);
            DB::table('work_order')->updateOrInsert(['work_order_id' => $num], [
                'operator_code' => $op, 'type' => $type, 'kind' => $kind, 'job_type_code' => $job,
                'priority' => $prio, 'status' => $status, 'customer_id' => $custId, 'tech_region_id' => $region,
                'contractor_id' => $ctr, 'team_id' => $team, 'assigned_technician_id' => $tech,
                'source_type' => 'MANUAL', 'scheduled_at' => $sched,
                'sla_due_at' => $slaMin === null ? null : now()->addMinutes($slaMin),
                'required_skills' => json_encode(['GPON']),
            ] + $t);
        }
    }
}
