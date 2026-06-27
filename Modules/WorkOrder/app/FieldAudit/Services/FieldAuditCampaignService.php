<?php

namespace Modules\WorkOrder\FieldAudit\Services;

use App\Foundation\Approvals\ApprovalRequest;
use App\Foundation\Approvals\ApprovalService;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Rules\RuleEngine;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Support\Facades\DB;
use Modules\WorkOrder\FieldAudit\Models\FieldAuditCampaign;
use Modules\WorkOrder\FieldAudit\Models\FieldAuditDiscrepancy;
use Modules\WorkOrder\FieldAudit\Models\FieldAuditExpectedItem;
use Modules\WorkOrder\FieldAudit\Models\FieldAuditObservation;
use Modules\WorkOrder\FieldAudit\Models\FieldAuditTask;

/**
 * FA-01/02/03 unified field-audit capability (one capability, audit_type-driven). Builds a
 * frozen expected-state snapshot per task, accepts field observations (idempotent for mobile
 * offline replay), compares expected vs observed to raise typed discrepancies, asks the rule
 * package for severity + route, and routes each discrepancy — risky routes (OSR correction /
 * write-off) gated by EM-CFG-04. FA never mutates OSR/instance state itself; it emits the
 * routed action for the owning module to perform (boundary rules).
 */
class FieldAuditCampaignService
{
    /** Routes that change OSR/asset state and therefore require approval. */
    private const RISKY_ROUTES = ['REQUEST_OSR_CORRECTION', 'REQUEST_WRITE_OFF'];

    public function __construct(
        private readonly EventBus $events,
        private readonly RuleEngine $rules,
        private readonly ApprovalService $approvals,
    ) {}

    public const TOPIC = 'field.audit';

    /** @param array<string,mixed> $data */
    public function createCampaign(array $data): FieldAuditCampaign
    {
        $campaign = FieldAuditCampaign::query()->create([
            'operator_code' => $data['operatorCode'] ?? Context::operatorCode(),
            'audit_type' => $data['auditType'], 'campaign_type' => $data['campaignType'],
            'area_code' => $data['areaCode'] ?? null, 'technology_family' => $data['technologyFamily'] ?? null,
            'status' => $data['scheduledStartAt'] ?? null ? FieldAuditCampaign::SCHEDULED : FieldAuditCampaign::DRAFT,
            'scheduled_start_at' => $data['scheduledStartAt'] ?? null, 'scheduled_end_at' => $data['scheduledEndAt'] ?? null,
            'created_by_user_id' => $data['createdByUserId'] ?? null,
        ]);
        $this->emit('FieldAuditCampaignCreated', $campaign->campaign_id, ['auditType' => $campaign->audit_type, 'campaignType' => $campaign->campaign_type]);

        return $campaign;
    }

    /**
     * Create an audit task and freeze its expected items (from OSR-INSTANCE bindings, supplied
     * as expectedItems[]). Idempotent by source_event_ref.
     *
     * @param array<string,mixed> $data
     */
    public function createTask(array $data): FieldAuditTask
    {
        $operator = $data['operatorCode'] ?? Context::operatorCode();
        Context::setOperatorCode($operator);

        $ref = $data['sourceEventRef'] ?? null;
        if ($ref && ($existing = FieldAuditTask::query()->where('operator_code', $operator)->where('source_event_ref', $ref)->first())) {
            return $existing;
        }

        return DB::transaction(function () use ($operator, $data, $ref) {
            $task = FieldAuditTask::query()->create([
                'operator_code' => $operator, 'campaign_id' => $data['campaignId'] ?? null,
                'audit_type' => $data['auditType'], 'task_type' => $data['taskType'] ?? 'CUSTOMER_PREMISES',
                'customer_id' => $data['customerId'] ?? null, 'account_id' => $data['accountId'] ?? null,
                'subscription_id' => $data['subscriptionId'] ?? null, 'homepass_id' => $data['homepassId'] ?? null,
                'wo_id' => $data['woId'] ?? null, 'assigned_to_user_id' => $data['assignedToUserId'] ?? null,
                'assigned_team_id' => $data['assignedTeamId'] ?? null, 'source_event_ref' => $ref,
                'status' => ($data['assignedToUserId'] ?? null) ? FieldAuditTask::ASSIGNED : FieldAuditTask::CREATED,
                'due_at' => $data['dueAt'] ?? null,
            ]);

            foreach ($data['expectedItems'] ?? [] as $item) {
                FieldAuditExpectedItem::query()->create([
                    'operator_code' => $operator, 'audit_task_id' => $task->audit_task_id,
                    'equipment_instance_id' => $item['equipmentInstanceId'] ?? null, 'sku_id' => $item['skuId'] ?? null,
                    'serial_number' => $item['serialNumber'] ?? null,
                    'expected_location_type' => $item['expectedLocationType'] ?? 'CUSTOMER_PREMISES',
                    'expected_location_ref' => $item['expectedLocationRef'] ?? ($data['customerId'] ?? null),
                    'expected_condition' => $item['expectedCondition'] ?? 'INSTALLED_WORKING',
                    'source_snapshot_json' => $item['snapshot'] ?? null,
                ]);
            }

            $this->emit('FieldAuditTaskCreated', $task->audit_task_id, ['auditType' => $task->audit_type, 'expectedCount' => count($data['expectedItems'] ?? [])]);
            if ($data['createWorkOrder'] ?? false) {
                $this->emit('FieldAuditWorkOrderRequested', $task->audit_task_id, ['kind' => 'FIELD_AUDIT']); // WO-01 consumes
            }

            return $task->refresh();
        });
    }

    /**
     * Submit one field observation; compare against its expected item and raise + route a typed
     * discrepancy if they differ. Idempotent by offline_client_ref (mobile offline replay).
     *
     * @param array<string,mixed> $data
     */
    public function submitObservation(FieldAuditTask $task, array $data): FieldAuditObservation
    {
        $operator = $task->operator_code;
        if (! empty($data['offlineClientRef'])) {
            $dupe = FieldAuditObservation::query()->where('operator_code', $operator)->where('offline_client_ref', $data['offlineClientRef'])->first();
            if ($dupe) {
                return $dupe;
            }
        }

        return DB::transaction(function () use ($task, $operator, $data) {
            $expected = isset($data['expectedItemId'])
                ? FieldAuditExpectedItem::query()->where('audit_task_id', $task->audit_task_id)->where('expected_item_id', $data['expectedItemId'])->first()
                : $this->matchExpected($task, $data['observedSerialNumber'] ?? null);

            $obs = FieldAuditObservation::query()->create([
                'operator_code' => $operator, 'audit_task_id' => $task->audit_task_id,
                'expected_item_id' => $expected?->expected_item_id,
                'observed_equipment_instance_id' => $data['observedEquipmentInstanceId'] ?? null,
                'observed_sku_id' => $data['observedSkuId'] ?? null, 'observed_serial_number' => $data['observedSerialNumber'] ?? null,
                'presence_status' => $data['presenceStatus'] ?? 'PRESENT', 'condition_status' => $data['conditionStatus'] ?? 'WORKING',
                'observed_location_ref' => $data['observedLocationRef'] ?? null,
                'photo_file_ids_json' => $data['photoFileIds'] ?? null,
                'gps_latitude' => $data['gpsLatitude'] ?? null, 'gps_longitude' => $data['gpsLongitude'] ?? null,
                'notes' => $data['notes'] ?? null, 'captured_by_user_id' => $data['capturedByUserId'] ?? null,
                'captured_at' => now(), 'offline_client_ref' => $data['offlineClientRef'] ?? null,
            ]);

            $type = $this->detectDiscrepancy($expected, $obs);
            if ($type) {
                $this->raiseDiscrepancy($task, $expected, $obs, $type);
            }

            $this->refreshTaskStatus($task);

            return $obs;
        });
    }

    private function matchExpected(FieldAuditTask $task, ?string $serial): ?FieldAuditExpectedItem
    {
        // Prefer an exact serial match; otherwise fall back to the task's expected item so a
        // mismatched serial surfaces as WRONG_SERIAL (a genuinely extra device is flagged by the
        // caller via presence_status = FOUND_EXTRA).
        if ($serial) {
            $exact = FieldAuditExpectedItem::query()->where('audit_task_id', $task->audit_task_id)->where('serial_number', $serial)->first();
            if ($exact) {
                return $exact;
            }
        }

        return FieldAuditExpectedItem::query()->where('audit_task_id', $task->audit_task_id)->first();
    }

    /** Expected vs observed comparison → discrepancy type (or null when they match). */
    private function detectDiscrepancy(?FieldAuditExpectedItem $expected, FieldAuditObservation $obs): ?string
    {
        return match (true) {
            $obs->presence_status === 'MISSING' => 'MISSING',
            $obs->presence_status === 'NOT_ACCESSIBLE' => 'NOT_ACCESSIBLE',
            $obs->presence_status === 'FOUND_EXTRA' || $expected === null => 'FOUND_EXTRA',
            in_array($obs->condition_status, ['DAMAGED', 'TAMPERED'], true) => 'DAMAGED',
            $expected->serial_number && $obs->observed_serial_number && $expected->serial_number !== $obs->observed_serial_number => 'WRONG_SERIAL',
            $expected->expected_location_ref && $obs->observed_location_ref && $expected->expected_location_ref !== $obs->observed_location_ref => 'WRONG_LOCATION',
            default => null,
        };
    }

    private function raiseDiscrepancy(FieldAuditTask $task, ?FieldAuditExpectedItem $expected, FieldAuditObservation $obs, string $type): FieldAuditDiscrepancy
    {
        // rules.field_audit.<type>.discrepancy: discrepancyType -> {severity, routeAction}.
        $decision = $this->rules->evaluate('rules.field_audit.'.strtolower($task->audit_type).'.discrepancy', [
            'discrepancyType' => $type, 'auditType' => $task->audit_type, 'conditionStatus' => $obs->condition_status,
        ]);
        $severity = $decision['severity'] ?? 'MEDIUM';
        $route = $decision['routeAction'] ?? 'CREATE_TICKET';

        $d = FieldAuditDiscrepancy::query()->create([
            'operator_code' => $task->operator_code, 'audit_task_id' => $task->audit_task_id,
            'expected_item_id' => $expected?->expected_item_id, 'observation_id' => $obs->observation_id,
            'discrepancy_type' => $type, 'severity' => $severity, 'status' => FieldAuditDiscrepancy::OPEN, 'route_action' => $route,
        ]);
        $this->emit('FieldAuditDiscrepancyOpened', $task->audit_task_id, ['discrepancyId' => $d->discrepancy_id, 'type' => $type, 'severity' => $severity, 'route' => $route]);
        $this->route($d);

        return $d->refresh();
    }

    private function route(FieldAuditDiscrepancy $d): void
    {
        if ($d->route_action === 'NO_ACTION') {
            $d->update(['status' => FieldAuditDiscrepancy::RESOLVED, 'resolved_at' => now()]);

            return;
        }

        if (in_array($d->route_action, self::RISKY_ROUTES, true)) {
            $req = $this->approvals->request([
                'operator_code' => $d->operator_code, 'entity_type' => 'FIELD_AUDIT_DISCREPANCY', 'action' => $d->route_action,
                'entity_ref' => $d->discrepancy_id, 'payload' => ['type' => $d->discrepancy_type, 'severity' => $d->severity],
            ]);
            $d->update(['approval_request_id' => $req->request_id, 'status' => $req->status === ApprovalRequest::AUTO_APPROVED ? FieldAuditDiscrepancy::ACTION_CREATED : FieldAuditDiscrepancy::PENDING_APPROVAL]);
            if ($req->status === ApprovalRequest::AUTO_APPROVED) {
                $this->emitOsrAction($d);
            }

            return;
        }

        // CREATE_TICKET / CREATE_RMA_RECOVERY: emit the routed request for the owning module.
        $refType = $d->route_action === 'CREATE_TICKET' ? 'TICKET' : 'OSR_RMA';
        $d->update(['status' => FieldAuditDiscrepancy::ACTION_CREATED, 'routed_ref_type' => $refType, 'routed_ref_id' => Id::make(strtolower($refType))]);
        $this->emit('FieldAuditDiscrepancyRouted', $d->audit_task_id, ['discrepancyId' => $d->discrepancy_id, 'route' => $d->route_action, 'refType' => $refType, 'refId' => $d->routed_ref_id]);
    }

    /** EM-CFG-04 approval callback for a risky OSR-correction/write-off route. */
    public function applyApprovalOutcome(FieldAuditDiscrepancy $d, string $outcome): FieldAuditDiscrepancy
    {
        if ($d->status !== FieldAuditDiscrepancy::PENDING_APPROVAL) {
            return $d;
        }
        if (strtoupper($outcome) === 'APPROVED') {
            $d->update(['status' => FieldAuditDiscrepancy::ACTION_CREATED]);
            $this->emitOsrAction($d);
        } else {
            $d->update(['status' => FieldAuditDiscrepancy::REJECTED]);
        }

        return $d->refresh();
    }

    public function resolveDiscrepancy(FieldAuditDiscrepancy $d, ?string $note = null): FieldAuditDiscrepancy
    {
        $d->update(['status' => FieldAuditDiscrepancy::RESOLVED, 'resolved_at' => now()]);
        $task = FieldAuditTask::query()->find($d->audit_task_id);
        if ($task) {
            $this->refreshTaskStatus($task);
        }

        return $d->refresh();
    }

    private function emitOsrAction(FieldAuditDiscrepancy $d): void
    {
        $refType = $d->route_action === 'REQUEST_WRITE_OFF' ? 'OSR_WRITE_OFF' : 'OSR_CORRECTION';
        $d->update(['routed_ref_type' => $refType, 'routed_ref_id' => Id::make('osr')]);
        $this->emit('FieldAuditDiscrepancyRouted', $d->audit_task_id, ['discrepancyId' => $d->discrepancy_id, 'route' => $d->route_action, 'refType' => $refType, 'refId' => $d->routed_ref_id]);
    }

    private function refreshTaskStatus(FieldAuditTask $task): void
    {
        $task->refresh();
        $hasOpen = $task->discrepancies()->whereIn('status', [FieldAuditDiscrepancy::OPEN, FieldAuditDiscrepancy::ROUTED, FieldAuditDiscrepancy::PENDING_APPROVAL, FieldAuditDiscrepancy::ACTION_CREATED])->exists();

        if ($hasOpen) {
            $task->update(['status' => FieldAuditTask::DISCREPANCY_OPEN, 'submitted_at' => $task->submitted_at ?? now()]);

            return;
        }
        // No open discrepancies (clean audit, or all routed-and-resolved): close the task.
        $task->update(['status' => FieldAuditTask::CLOSED, 'closed_at' => now(), 'submitted_at' => $task->submitted_at ?? now()]);
        $this->emit('FieldAuditTaskClosed', $task->audit_task_id, ['discrepancies' => $task->discrepancies()->count()]);
    }

    /** @param array<string,mixed> $extra */
    private function emit(string $type, string $aggregateId, array $extra = []): void
    {
        $this->events->publish(new DomainEvent(
            type: $type, topic: self::TOPIC,
            payload: ['aggregateId' => $aggregateId] + $extra,
            aggregateType: 'FieldAudit', aggregateId: $aggregateId,
        ));
    }
}
