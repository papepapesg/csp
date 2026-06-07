<?php

namespace Modules\WorkOrder\Services;

use App\Foundation\Approvals\ApprovalService;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Rules\RuleEngine;
use App\Foundation\Support\Id;
use Modules\WorkOrder\Models\FieldAudit;

/**
 * FA-01/02/03 field-audit lifecycle. schedule() creates the audit; submitFindings()
 * records the field findings + photos, runs the kind's severity rule package
 * (rules.field_audit.<kind>.severity), and routes a HIGH/CRITICAL outcome to an
 * EM-CFG-04 approval before closing — otherwise auto-closes. The rule package +
 * target linkage are the only per-kind difference.
 */
class FieldAuditService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly RuleEngine $rules,
        private readonly ApprovalService $approvals,
    ) {}

    /** @param array<string,mixed> $data kind, target_type, target_ref, assigned_to, scheduled_at */
    public function schedule(array $data): FieldAudit
    {
        $audit = FieldAudit::query()->create([
            'audit_id' => Id::make('fa'),
            'kind' => $data['kind'],
            'target_type' => $data['target_type'] ?? null,
            'target_ref' => $data['target_ref'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'scheduled_at' => $data['scheduled_at'] ?? now(),
            'status' => FieldAudit::SCHEDULED,
        ]);
        $this->emit($audit, 'FieldAuditScheduled');

        return $audit;
    }

    /**
     * @param  array<string,mixed>  $findings
     * @param  array<int,string>  $photoFileIds
     */
    public function submitFindings(FieldAudit $audit, array $findings, array $photoFileIds = [], ?string $actor = null): FieldAudit
    {
        $kind = strtolower($audit->kind);
        $assessment = $this->rules->evaluate("rules.field_audit.{$kind}.severity", $findings + ['kind' => $audit->kind]);
        $severity = $assessment['severity'] ?? 'OK';
        $outcome = $assessment['outcome'] ?? 'VERIFIED';

        $audit->fill([
            'findings' => $findings,
            'photo_file_ids' => $photoFileIds,
            'severity' => $severity,
            'outcome' => $outcome,
            'submitted_at' => now(),
            'status' => FieldAudit::FINDINGS_SUBMITTED,
        ]);

        // Route severe findings to approval (EM-CFG-04); otherwise auto-close.
        if (in_array($severity, ['HIGH', 'CRITICAL'], true)) {
            $request = $this->approvals->request([
                'entity_type' => 'FIELD_AUDIT',
                'action' => $audit->kind,
                'entity_ref' => $audit->audit_id,
                'payload' => ['severity' => $severity, 'outcome' => $outcome],
                'requested_by' => $actor,
            ]);
            $audit->approval_request_id = $request->request_id;
            $audit->status = $request->status === 'AUTO_APPROVED' ? FieldAudit::CLOSED : FieldAudit::UNDER_REVIEW;
            $audit->closed_at = $request->status === 'AUTO_APPROVED' ? now() : null;
        } else {
            $audit->status = FieldAudit::CLOSED;
            $audit->closed_at = now();
        }
        $audit->save();

        $this->emit($audit, 'FieldAuditFindingsSubmitted');
        if ($audit->status === FieldAudit::CLOSED) {
            $this->emit($audit, 'FieldAuditClosed');
        }

        return $audit->refresh();
    }

    private function emit(FieldAudit $audit, string $type): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: 'field.audit',
            payload: ['auditId' => $audit->audit_id, 'kind' => $audit->kind, 'severity' => $audit->severity, 'status' => $audit->status],
            aggregateType: 'FieldAudit',
            aggregateId: $audit->audit_id,
        ));
    }
}
