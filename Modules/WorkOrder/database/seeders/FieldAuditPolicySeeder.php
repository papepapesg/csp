<?php

namespace Modules\WorkOrder\Database\Seeders;

use App\Foundation\Approvals\ApprovalDefinition;
use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Modules\Rules\Models\DecisionTable;

/**
 * Seeds the FA-01/02/03 severity rule packages. Each audit kind maps findings to a
 * severity + outcome; HIGH/CRITICAL route to approval. Operators tune thresholds.
 */
class FieldAuditPolicySeeder extends Seeder
{
    public function run(): void
    {
        // EQUIPMENT: a missing/serial-mismatch device is critical (possible loss/fraud).
        $this->deploy('rules.field_audit.equipment.severity', [
            ['ruleId' => 'R-FA-EQ-001', 'when' => [['var' => 'serialMatch', 'op' => 'falsy']], 'then' => ['severity' => 'CRITICAL', 'outcome' => 'DISCREPANCY']],
            ['ruleId' => 'R-FA-EQ-002', 'when' => [['var' => 'physicalDamage', 'op' => 'truthy']], 'then' => ['severity' => 'MEDIUM', 'outcome' => 'DAMAGE']],
        ], ['severity' => 'OK', 'outcome' => 'VERIFIED'], ['serialMatch', 'physicalDamage', 'kind']);

        // NETWORK: signal/power out of spec.
        $this->deploy('rules.field_audit.network.severity', [
            ['ruleId' => 'R-FA-NW-001', 'when' => [['var' => 'signalOutOfSpec', 'op' => 'truthy']], 'then' => ['severity' => 'HIGH', 'outcome' => 'SIGNAL_FAULT']],
        ], ['severity' => 'OK', 'outcome' => 'VERIFIED'], ['signalOutOfSpec', 'kind']);

        // KYC: identity mismatch is critical (fraud).
        $this->deploy('rules.field_audit.kyc.severity', [
            ['ruleId' => 'R-FA-KYC-001', 'when' => [['var' => 'identityMatch', 'op' => 'falsy']], 'then' => ['severity' => 'CRITICAL', 'outcome' => 'FRAUD_SUSPECTED']],
            ['ruleId' => 'R-FA-KYC-002', 'when' => [['var' => 'addressMatch', 'op' => 'falsy']], 'then' => ['severity' => 'MEDIUM', 'outcome' => 'ADDRESS_DISCREPANCY']],
        ], ['severity' => 'OK', 'outcome' => 'VERIFIED'], ['identityMatch', 'addressMatch', 'kind']);

        // Severe audits require a supervisor approval before close (EM-CFG-04).
        ApprovalDefinition::query()->updateOrCreate(
            ['operator_code' => config('sophix.default_operator', 'WIK'), 'entity_type' => 'FIELD_AUDIT', 'action' => null],
            ['definition_id' => Id::make('appd'), 'approver_roles' => ['OSR_SUPERVISOR', 'CUSTOMER_CARE_SUPERVISOR'], 'required_approvals' => 1, 'active' => true],
        );
    }

    private function deploy(string $ruleSet, array $rules, array $default, array $inputs): void
    {
        DecisionTable::query()->updateOrCreate(
            ['rule_set' => $ruleSet, 'version' => 1, 'operator_code' => null],
            ['table_id' => Id::make('dt'), 'name' => $ruleSet, 'hit_policy' => 'FIRST', 'inputs' => $inputs, 'rules' => $rules, 'default_output' => $default, 'status' => DecisionTable::DEPLOYED],
        );
    }
}
