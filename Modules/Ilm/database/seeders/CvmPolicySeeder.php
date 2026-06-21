<?php

namespace Modules\Ilm\Database\Seeders;

use App\Foundation\Approvals\ApprovalDefinition;
use App\Foundation\Support\Id;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Rules\Models\DecisionTable;

/**
 * EM-03 CVM rule packages (DD §6). Segmentation, activity, and offer logic are operator-tunable
 * decision tables — the MVP is rules-based, not ML, and segments/offers/approval thresholds are
 * config (Rules Studio), not code.
 */
class CvmPolicySeeder extends Seeder
{
    public function run(): void
    {
        // rules.cvm.segmentation — facts -> {segment, churnRiskScore, activityType, activityPriority}.
        DecisionTable::query()->updateOrCreate(
            ['rule_set' => 'rules.cvm.segmentation', 'version' => 1, 'operator_code' => null],
            [
                'table_id' => Id::make('dt'), 'name' => 'CVM segmentation', 'hit_policy' => 'FIRST',
                'inputs' => ['dunningLevel', 'complaintCount90d', 'daysSinceLastPayment', 'openTicketCount', 'activeSubscriptionCount'],
                'rules' => [
                    // High-risk dunning customer (the DD's worked Drools example).
                    ['ruleId' => 'R-CVM-SEG-001', 'when' => [['var' => 'dunningLevel', 'op' => 'gte', 'value' => 2], ['var' => 'complaintCount90d', 'op' => 'gte', 'value' => 2]],
                        'then' => ['segment' => 'RETENTION_HIGH_RISK', 'reasonCode' => 'DUNNING_AND_COMPLAINTS', 'churnRiskScore' => 80, 'activityType' => 'PAYMENT_RECOVERY', 'activityPriority' => 'HIGH', 'assignTeam' => 'team-retention']],
                    // In dunning but no complaints — payment recovery, medium.
                    ['ruleId' => 'R-CVM-SEG-002', 'when' => [['var' => 'dunningLevel', 'op' => 'gte', 'value' => 1]],
                        'then' => ['segment' => 'RECOVERY', 'reasonCode' => 'DUNNING', 'churnRiskScore' => 55, 'activityType' => 'PAYMENT_RECOVERY', 'activityPriority' => 'MEDIUM']],
                    // Repeated complaints, not in dunning — service recovery.
                    ['ruleId' => 'R-CVM-SEG-003', 'when' => [['var' => 'complaintCount90d', 'op' => 'gte', 'value' => 3]],
                        'then' => ['segment' => 'SERVICE_RECOVERY', 'reasonCode' => 'REPEAT_COMPLAINTS', 'churnRiskScore' => 60, 'activityType' => 'SERVICE_RECOVERY', 'activityPriority' => 'MEDIUM']],
                    // Multi-service, healthy — upsell candidate.
                    ['ruleId' => 'R-CVM-SEG-004', 'when' => [['var' => 'activeSubscriptionCount', 'op' => 'gte', 'value' => 1], ['var' => 'dunningLevel', 'op' => 'eq', 'value' => 0]],
                        'then' => ['segment' => 'UPSELL', 'reasonCode' => 'HEALTHY_ACCOUNT', 'churnRiskScore' => 10, 'upsellScore' => 60, 'activityType' => 'UPSELL_OFFER', 'activityPriority' => 'LOW']],
                ],
                'default_output' => ['churnRiskScore' => 5],
                'status' => DecisionTable::DEPLOYED,
            ],
        );

        // rules.cvm.offer — offer approval threshold (high-value retention discount needs EM-CFG-04).
        DecisionTable::query()->updateOrCreate(
            ['rule_set' => 'rules.cvm.offer', 'version' => 1, 'operator_code' => null],
            [
                'table_id' => Id::make('dt'), 'name' => 'CVM offer approval', 'hit_policy' => 'FIRST',
                'inputs' => ['offerType', 'discountPercent'],
                'rules' => [
                    ['ruleId' => 'R-CVM-OFR-001', 'when' => [['var' => 'offerType', 'op' => 'eq', 'value' => 'RETENTION_DISCOUNT'], ['var' => 'discountPercent', 'op' => 'gt', 'value' => 10]],
                        'then' => ['requireApproval' => true, 'approvalPolicy' => 'CVM_HIGH_VALUE_RETENTION_OFFER']],
                    ['ruleId' => 'R-CVM-OFR-002', 'when' => [['var' => 'offerType', 'op' => 'eq', 'value' => 'GOODWILL_CREDIT']],
                        'then' => ['requireApproval' => true, 'approvalPolicy' => 'CVM_GOODWILL_CREDIT']],
                ],
                'default_output' => ['requireApproval' => false],
                'status' => DecisionTable::DEPLOYED,
            ],
        );

        // EM-CFG-04 policy: a CVM high-value retention offer needs approval (no threshold = always).
        // It is a real HIERARCHY, not a single sign-off: stage 1 the CVM manager (a platform role),
        // then stage 2 a named director — a senior who holds NO platform role, just an invited login.
        $operator = config('sophix.default_operator', 'WIK');
        $director = User::query()->firstOrCreate(
            ['email' => 'cvm.director@wik.sn', 'operator_code' => $operator],
            ['name' => 'CVM Director', 'status' => 'INVITED', 'password' => Hash::make(Str::random(40))],
        );

        ApprovalDefinition::defineChain($operator, 'CVM_OFFER', 'CVM_HIGH_VALUE_RETENTION_OFFER', [
            ['name' => 'CVM manager review', 'approver_kind' => 'ROLE', 'approver_roles' => ['CVM_MANAGER']],
            ['name' => 'Director sign-off', 'approver_kind' => 'USER', 'approver_user_ref' => $director->uid, 'approver_email' => $director->email],
        ]);
    }
}
