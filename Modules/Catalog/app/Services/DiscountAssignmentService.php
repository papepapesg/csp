<?php

namespace Modules\Catalog\Services;

use App\Foundation\Approvals\ApprovalRequest;
use App\Foundation\Approvals\ApprovalService;
use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Events\CatalogEvents;
use Modules\Catalog\Models\Discount;
use Modules\Catalog\Models\DiscountAssignment;
use Modules\Catalog\Models\DiscountAssignmentHistory;

/**
 * SIP-03 discount assignment lifecycle. Creates and governs assignment records — it never
 * touches money (DIS-OP-01/BIL do that). Validates the discount exists + is active (R-DA-01),
 * blocks duplicate active grants (R-DA-05), and routes high-value/long-duration/manual grants
 * through EM-CFG-04 (R-DA-07/11): an approval-required grant lands in PENDING_APPROVAL and only
 * activates on the approval callback. Cancellation stops future runtime only (R-DA-08).
 */
class DiscountAssignmentService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * @param array<string,mixed> $data
     * @param bool $previewOnly when true, returns eligibility + approvalRequired without writing
     * @return array{assignment:?DiscountAssignment, approvalRequired:bool, eligible:bool, reason:?string}
     */
    public function create(array $data, bool $previewOnly = false): array
    {
        $operator = $data['operatorCode'] ?? Context::operatorCode();
        Context::setOperatorCode($operator);

        // R-SIP-DA-01: discount must exist and be active in PLM-CFG-04.
        $discount = Discount::query()->where('operator_code', $operator)->where('code', $data['discountCode'])->where('status', 'ACTIVE')->first();
        if (! $discount) {
            if ($previewOnly) {
                return ['assignment' => null, 'approvalRequired' => false, 'eligible' => false, 'reason' => 'DISCOUNT_NOT_FOUND_OR_INACTIVE'];
            }
            throw DomainException::ruleRejected('DISCOUNT_NOT_FOUND_OR_INACTIVE', 'Discount code is not an active catalog item.');
        }

        // R-SIP-DA-05: block a duplicate active grant for the same discount + scope + window.
        $dupe = DiscountAssignment::query()->where('operator_code', $operator)
            ->where('discount_code', $data['discountCode'])
            ->where('scope_type', $data['scopeType'])->where('scope_ref_id', $data['scopeRefId'] ?? null)
            ->whereIn('status', [DiscountAssignment::ACTIVE, DiscountAssignment::PENDING_APPROVAL])->first();
        if ($dupe && ! $previewOnly) {
            throw DomainException::conflict('An active or pending assignment already exists for this discount + scope.');
        }

        // R-SIP-DA-07/11: ask EM-CFG-04 whether this grant needs approval.
        $estimatedValue = (float) ($data['estimatedValue'] ?? 0);
        $durationDays = isset($data['validFrom'], $data['validTo'])
            ? Carbon::parse($data['validFrom'])->diffInDays(Carbon::parse($data['validTo'])) : 0;
        $needsApproval = $this->approvalNeeded($operator, $data, $discount, $estimatedValue, $durationDays);

        if ($previewOnly) {
            return ['assignment' => null, 'approvalRequired' => $needsApproval, 'eligible' => ! ($dupe), 'reason' => $dupe ? 'DUPLICATE_ACTIVE' : null];
        }

        return DB::transaction(function () use ($operator, $data, $discount, $needsApproval, $estimatedValue) {
            $assignment = DiscountAssignment::query()->create([
                'operator_code' => $operator,
                'discount_code' => $data['discountCode'], 'discount_id' => $discount->discount_id,
                'scope' => $data['scopeType'], 'scope_ref' => $data['scopeRefId'] ?? null, // legacy mirror
                'scope_type' => $data['scopeType'], 'scope_ref_id' => $data['scopeRefId'] ?? null,
                'customer_id' => $data['customerId'] ?? null, 'account_id' => $data['accountId'] ?? null,
                'subscription_id' => $data['subscriptionId'] ?? null, 'package_ref' => $data['packageRef'] ?? null,
                'campaign_id' => $data['campaignId'] ?? null, 'campaign_code' => $data['campaignId'] ?? null,
                'franchise_id' => $data['franchiseId'] ?? null, 'reason_code' => $data['reasonCode'] ?? null,
                'source_channel' => $data['sourceChannel'] ?? 'API',
                'valid_from' => $data['validFrom'] ?? now()->toDateString(), 'valid_to' => $data['validTo'] ?? null,
                'assignment_priority' => $data['priority'] ?? 100, 'stacking_group_code' => $data['stackingGroupCode'] ?? null,
                'status' => $needsApproval ? DiscountAssignment::PENDING_APPROVAL : DiscountAssignment::ACTIVE,
                'active' => ! $needsApproval, // legacy flag
                'metadata_json' => $data['metadata'] ?? null, 'created_by_user_id' => $data['createdByUserId'] ?? null,
                'activated_at' => $needsApproval ? null : now(),
            ]);
            $this->recordHistory($assignment, null, $assignment->status, $needsApproval ? 'APPROVAL_REQUIRED' : 'AUTO_ACTIVATED', $data['createdByUserId'] ?? null);
            $this->emit(CatalogEvents::DISCOUNT_ASSIGNMENT_CREATED, $assignment);

            if ($needsApproval) {
                $req = $this->approvals->request([
                    'operator_code' => $operator, 'entity_type' => 'DISCOUNT_ASSIGNMENT', 'action' => 'DISCOUNT_ASSIGNMENT_CREATE',
                    'entity_ref' => $assignment->assignment_id, 'amount' => $estimatedValue,
                    'payload' => ['discountCode' => $assignment->discount_code, 'reasonCode' => $assignment->reason_code],
                    'requested_by' => $data['createdByUserId'] ?? null,
                ]);
                $assignment->update(['approval_request_id' => $req->request_id]);
                // EM-CFG-04 may auto-approve (no policy / under threshold) — apply the outcome now.
                if ($req->status === ApprovalRequest::AUTO_APPROVED) {
                    $this->applyApprovalOutcome($assignment, 'APPROVED');
                } else {
                    $this->emit(CatalogEvents::DISCOUNT_ASSIGNMENT_APPROVAL_REQUIRED, $assignment);
                }
            } else {
                $this->emit(CatalogEvents::DISCOUNT_ASSIGNMENT_ACTIVATED, $assignment);
            }

            return ['assignment' => $assignment->refresh(), 'approvalRequired' => $needsApproval, 'eligible' => true, 'reason' => null];
        });
    }

    /** EM-CFG-04 approval callback (R-SIP-DA-11): activate on APPROVED, reject on REJECTED. */
    public function applyApprovalOutcome(DiscountAssignment $assignment, string $outcome, ?string $actor = null): DiscountAssignment
    {
        if ($assignment->status !== DiscountAssignment::PENDING_APPROVAL) {
            return $assignment;
        }
        if (strtoupper($outcome) === 'APPROVED') {
            $assignment->update(['status' => DiscountAssignment::ACTIVE, 'active' => true, 'activated_at' => now()]);
            $this->recordHistory($assignment, DiscountAssignment::PENDING_APPROVAL, DiscountAssignment::ACTIVE, 'APPROVED', $actor);
            $this->emit(CatalogEvents::DISCOUNT_ASSIGNMENT_ACTIVATED, $assignment);
        } else {
            $assignment->update(['status' => DiscountAssignment::REJECTED, 'active' => false]);
            $this->recordHistory($assignment, DiscountAssignment::PENDING_APPROVAL, DiscountAssignment::REJECTED, 'REJECTED', $actor);
            $this->emit(CatalogEvents::DISCOUNT_ASSIGNMENT_REJECTED, $assignment);
        }

        return $assignment->refresh();
    }

    /** R-SIP-DA-08: cancellation stops FUTURE runtime only; past invoice/wallet impact needs a BIL adjustment. */
    public function cancel(DiscountAssignment $assignment, string $reasonCode, ?string $actor = null): DiscountAssignment
    {
        if (in_array($assignment->status, [DiscountAssignment::CANCELLED, DiscountAssignment::EXPIRED, DiscountAssignment::REJECTED], true)) {
            throw DomainException::conflict('Assignment is already terminal.');
        }
        $old = $assignment->status;
        $assignment->update(['status' => DiscountAssignment::CANCELLED, 'active' => false, 'cancelled_at' => now()]);
        $this->recordHistory($assignment, $old, DiscountAssignment::CANCELLED, $reasonCode, $actor);
        $this->emit(CatalogEvents::DISCOUNT_ASSIGNMENT_CANCELLED, $assignment, ['reasonCode' => $reasonCode]);

        return $assignment->refresh();
    }

    /** Sweep ACTIVE assignments past valid_to into EXPIRED. */
    public function expireDue(?string $operator = null): int
    {
        $due = DiscountAssignment::query()->where('status', DiscountAssignment::ACTIVE)
            ->whereNotNull('valid_to')->where('valid_to', '<', now()->toDateString())
            ->when($operator, fn ($q) => $q->where('operator_code', $operator))->get();
        foreach ($due as $a) {
            $a->update(['status' => DiscountAssignment::EXPIRED, 'active' => false]);
            $this->recordHistory($a, DiscountAssignment::ACTIVE, DiscountAssignment::EXPIRED, 'VALID_TO_PASSED', null);
            $this->emit(CatalogEvents::DISCOUNT_ASSIGNMENT_EXPIRED, $a);
        }

        return $due->count();
    }

    /** R-SIP-DA runtime query API: effective ACTIVE assignments for a scope on a date (DIS-OP-01 reads this). */
    public function effective(string $operator, array $filters, ?string $onDate = null): \Illuminate\Support\Collection
    {
        $date = $onDate ?: now()->toDateString();

        return DiscountAssignment::query()->where('operator_code', $operator)->where('status', DiscountAssignment::ACTIVE)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
            ->when($filters['subscriptionId'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('subscription_id', $v)->orWhere(fn ($x) => $x->where('scope_type', 'SUBSCRIPTION')->where('scope_ref_id', $v))))
            ->when($filters['customerId'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('customer_id', $v)->orWhere(fn ($x) => $x->where('scope_type', 'CUSTOMER')->where('scope_ref_id', $v))))
            ->orderBy('assignment_priority')->get();
    }

    /** @param array<string,mixed> $data */
    private function approvalNeeded(string $operator, array $data, Discount $discount, float $estimatedValue, int $durationDays): bool
    {
        // Operator policy is owned by EM-CFG-04; if a definition exists it decides. The threshold
        // there gates on estimatedValue; long-duration/manual reasons can be modelled as zero-threshold.
        $def = \App\Foundation\Approvals\ApprovalDefinition::query()
            ->where('operator_code', $operator)->where('entity_type', 'DISCOUNT_ASSIGNMENT')->where('active', true)
            ->where(fn ($q) => $q->whereNull('action')->orWhere('action', 'DISCOUNT_ASSIGNMENT_CREATE'))->first();
        if (! $def) {
            return false;
        }

        return $def->threshold_amount === null || $estimatedValue >= (float) $def->threshold_amount;
    }

    private function recordHistory(DiscountAssignment $a, ?string $old, string $new, ?string $reason, ?string $actor): void
    {
        DiscountAssignmentHistory::query()->create([
            'history_id' => Id::make('dash'), 'assignment_id' => $a->assignment_id, 'operator_code' => $a->operator_code,
            'old_status' => $old, 'new_status' => $new, 'reason_code' => $reason, 'changed_by_user_id' => $actor, 'changed_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function emit(string $type, DiscountAssignment $a, array $extra = []): void
    {
        $this->events->publish(new DomainEvent(
            type: $type, topic: CatalogEvents::TOPIC,
            payload: ['assignmentId' => $a->assignment_id, 'discountCode' => $a->discount_code, 'scopeType' => $a->scope_type, 'scopeRefId' => $a->scope_ref_id, 'status' => $a->status, 'operatorCode' => $a->operator_code] + $extra,
            aggregateType: 'DiscountAssignment', aggregateId: $a->assignment_id,
        ));
    }
}
