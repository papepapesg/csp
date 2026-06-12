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

        // Discrepancy routing rules (campaign/task model): discrepancyType -> {severity, routeAction}.
        $this->deploy('rules.field_audit.equipment.discrepancy', [
            ['ruleId' => 'R-FA-EQD-001', 'when' => [['var' => 'discrepancyType', 'op' => 'eq', 'value' => 'MISSING']], 'then' => ['severity' => 'HIGH', 'routeAction' => 'CREATE_RMA_RECOVERY']],
            ['ruleId' => 'R-FA-EQD-002', 'when' => [['var' => 'discrepancyType', 'op' => 'eq', 'value' => 'WRONG_SERIAL']], 'then' => ['severity' => 'HIGH', 'routeAction' => 'REQUEST_OSR_CORRECTION']],
            ['ruleId' => 'R-FA-EQD-003', 'when' => [['var' => 'discrepancyType', 'op' => 'eq', 'value' => 'DAMAGED']], 'then' => ['severity' => 'MEDIUM', 'routeAction' => 'CREATE_RMA_RECOVERY']],
            ['ruleId' => 'R-FA-EQD-004', 'when' => [['var' => 'discrepancyType', 'op' => 'eq', 'value' => 'FOUND_EXTRA']], 'then' => ['severity' => 'MEDIUM', 'routeAction' => 'REQUEST_OSR_CORRECTION']],
            ['ruleId' => 'R-FA-EQD-005', 'when' => [['var' => 'discrepancyType', 'op' => 'eq', 'value' => 'WRONG_LOCATION']], 'then' => ['severity' => 'MEDIUM', 'routeAction' => 'REQUEST_OSR_CORRECTION']],
            ['ruleId' => 'R-FA-EQD-006', 'when' => [['var' => 'discrepancyType', 'op' => 'eq', 'value' => 'NOT_ACCESSIBLE']], 'then' => ['severity' => 'LOW', 'routeAction' => 'CREATE_TICKET']],
        ], ['severity' => 'MEDIUM', 'routeAction' => 'CREATE_TICKET'], ['discrepancyType', 'auditType', 'conditionStatus']);

        $this->deploy('rules.field_audit.network.discrepancy', [
            ['ruleId' => 'R-FA-NWD-001', 'when' => [['var' => 'discrepancyType', 'op' => 'eq', 'value' => 'MISSING']], 'then' => ['severity' => 'HIGH', 'routeAction' => 'CREATE_TICKET']],
            ['ruleId' => 'R-FA-NWD-002', 'when' => [['var' => 'discrepancyType', 'op' => 'eq', 'value' => 'DAMAGED']], 'then' => ['severity' => 'HIGH', 'routeAction' => 'CREATE_TICKET']],
        ], ['severity' => 'MEDIUM', 'routeAction' => 'CREATE_TICKET'], ['discrepancyType', 'auditType', 'conditionStatus']);

        $this->deploy('rules.field_audit.kyc.discrepancy', [
            ['ruleId' => 'R-FA-KYD-001', 'when' => [['var' => 'discrepancyType', 'op' => 'eq', 'value' => 'WRONG_LOCATION']], 'then' => ['severity' => 'CRITICAL', 'routeAction' => 'CREATE_TICKET']],
        ], ['severity' => 'CRITICAL', 'routeAction' => 'CREATE_TICKET'], ['discrepancyType', 'auditType', 'conditionStatus']);

        // Severe audits require a supervisor approval before close (EM-CFG-04).
        ApprovalDefinition::query()->updateOrCreate(
            ['operator_code' => config('sophix.default_operator', 'WIK'), 'entity_type' => 'FIELD_AUDIT', 'action' => null],
            ['definition_id' => Id::make('appd'), 'approver_roles' => ['OSR_SUPERVISOR', 'CUSTOMER_CARE_SUPERVISOR'], 'required_approvals' => 1, 'active' => true],
        );
        // Risky discrepancy routes (OSR correction / write-off) require approval before the OSR action.
        ApprovalDefinition::query()->updateOrCreate(
            ['operator_code' => config('sophix.default_operator', 'WIK'), 'entity_type' => 'FIELD_AUDIT_DISCREPANCY', 'action' => null],
            ['definition_id' => Id::make('appd'), 'approver_roles' => ['OSR_SUPERVISOR'], 'required_approvals' => 1, 'active' => true],
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
