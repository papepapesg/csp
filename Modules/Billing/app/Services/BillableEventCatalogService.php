<?php

namespace Modules\Billing\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use Illuminate\Support\Collection;
use Modules\Billing\Events\BillingEvents;
use Modules\Billing\Models\BillableEvent;
use Modules\Billing\Models\BillableEventCategory;

/**
 * BIL-CFG-01 BillableEvent catalog administration + the runtime resolution
 * BIL-01 uses when a workflow's bil01-emit-intent arrives: match ACTIVE events
 * by (operatorCode, intent code), filter by applicability, order by
 * display_order. The catalog governs trigger taxonomy, signed-amount semantics
 * and operator scoping; charging itself stays in BIL-01.
 */
class BillableEventCatalogService
{
    public function __construct(private readonly EventBus $events) {}

    /** @param array<string,mixed> $data */
    public function create(array $data, ?string $createdBy = null): BillableEvent
    {
        $operator = $data['operator_code'] ?? Context::operatorCode();

        // R-BIL-CFG-01-B-1: code unique per operator (excluding retired).
        $duplicate = BillableEvent::query()
            ->where('operator_code', $operator)->where('code', $data['code'])
            ->where('status', '!=', BillableEvent::RETIRED)->exists();
        if ($duplicate) {
            throw DomainException::conflict("BillableEvent '{$data['code']}' already exists for this operator.");
        }

        $this->validateRules($operator, $data);

        $event = BillableEvent::query()->create([
            'operator_code' => $operator,
            'code' => $data['code'],
            'description' => $data['description'],
            'category_code' => $data['category_code'],
            'service_refs' => $data['service_refs'] ?? [],
            'currency' => $data['currency'] ?? 'KES',
            'applicability' => $data['applicability'] ?? 'ANY',
            'amount_sign_policy' => $data['amount_sign_policy'] ?? 'POSITIVE_ONLY',
            'pay_first_required' => $data['pay_first_required'] ?? true,
            'trigger_type' => $data['trigger_type'],
            'trigger_intent_code' => $data['trigger_intent_code'] ?? null,
            'trigger_event_type' => $data['trigger_event_type'] ?? null,
            'trigger_filter_drl' => $data['trigger_filter_drl'] ?? null,
            'trigger_schedule' => $data['trigger_schedule'] ?? null,
            'state_callback' => $data['state_callback'] ?? null,
            'eligibility_franchise_refs' => $data['eligibility_franchise_refs'] ?? null,
            'eligibility_package_refs' => $data['eligibility_package_refs'] ?? null,
            'eligibility_segment_refs' => $data['eligibility_segment_refs'] ?? null,
            'display_order' => $data['display_order'] ?? 100,
            'status' => $data['status'] ?? BillableEvent::DRAFT,
            'notes' => $data['notes'] ?? null,
            'created_by' => $createdBy,
        ]);

        $this->emitChanged($event, 'CREATED');

        return $event;
    }

    /** @param array<string,mixed> $data */
    public function update(BillableEvent $event, array $data, ?string $updatedBy = null): BillableEvent
    {
        // R-BIL-CFG-01-B-2: code is the audit-trail key, immutable.
        if (isset($data['code']) && $data['code'] !== $event->code) {
            throw DomainException::ruleRejected('CODE_IMMUTABLE', 'A BillableEvent code cannot be renamed; retire and re-create.');
        }
        // R-BIL-CFG-01-AS-5 / T-8 / B-4: sign policy, trigger model and currency
        // are immutable once ACTIVE — switching requires retire + re-create.
        if ($event->status === BillableEvent::ACTIVE) {
            foreach (['amount_sign_policy', 'trigger_type', 'currency'] as $frozen) {
                if (isset($data[$frozen]) && $data[$frozen] !== $event->{$frozen}) {
                    throw DomainException::ruleRejected('FIELD_IMMUTABLE_WHEN_ACTIVE', "{$frozen} is immutable on an ACTIVE event; retire and re-create.");
                }
            }
        }

        $this->validateRules($event->operator_code, array_merge($event->only([
            'category_code', 'trigger_type', 'trigger_intent_code', 'trigger_event_type',
            'trigger_schedule', 'amount_sign_policy', 'applicability', 'pay_first_required', 'state_callback',
        ]), $data));

        $event->fill(collect($data)->except(['operator_code', 'code', 'status'])->all());
        $event->updated_by = $updatedBy;
        $event->save();
        $this->emitChanged($event, 'UPDATED');

        return $event;
    }

    public function activate(BillableEvent $event, ?string $actor = null): BillableEvent
    {
        if ($event->status !== BillableEvent::DRAFT) {
            throw DomainException::conflict("Only a DRAFT event can be activated (status: {$event->status}).");
        }
        $event->update(['status' => BillableEvent::ACTIVE, 'updated_by' => $actor]);
        $this->emitChanged($event, 'ACTIVATED');

        return $event;
    }

    public function retire(BillableEvent $event, ?string $actor = null): BillableEvent
    {
        if ($event->status === BillableEvent::RETIRED) {
            return $event;
        }
        $event->update(['status' => BillableEvent::RETIRED, 'retired_at' => now(), 'updated_by' => $actor]);
        $this->emitChanged($event, 'RETIRED');

        return $event;
    }

    /**
     * Runtime resolution for BIL-01 (R-BIL-CFG-01-T-2): the ACTIVE events
     * matching an intent code (by trigger_intent_code, falling back to the
     * event code itself), applicability-filtered, in display_order.
     *
     * @return Collection<int,BillableEvent>
     */
    public function resolve(string $operator, string $intentCode, string $billingMode = 'POSTPAID'): Collection
    {
        return BillableEvent::query()
            ->where('operator_code', $operator)
            ->where('status', BillableEvent::ACTIVE)
            ->where(fn ($q) => $q->where('trigger_intent_code', $intentCode)->orWhere('code', $intentCode))
            ->orderBy('display_order')->orderBy('code')
            ->get()
            ->filter(fn (BillableEvent $e) => $e->appliesToBillingMode($billingMode))
            ->values();
    }

    /** Does the operator govern intents through the catalog at all? */
    public function operatorHasCatalog(string $operator): bool
    {
        return BillableEvent::query()
            ->where('operator_code', $operator)
            ->where('status', BillableEvent::ACTIVE)
            ->exists();
    }

    /** @param array<string,mixed> $data */
    private function validateRules(string $operator, array $data): void
    {
        if (isset($data['amount_sign_policy']) && ! in_array($data['amount_sign_policy'], BillableEvent::SIGN_POLICIES, true)) {
            throw DomainException::ruleRejected('INVALID_SIGN_POLICY', 'amount_sign_policy must be POSITIVE_ONLY, NEGATIVE_ONLY or SIGNED.');
        }
        if (isset($data['applicability']) && ! in_array($data['applicability'], BillableEvent::APPLICABILITIES, true)) {
            throw DomainException::ruleRejected('INVALID_APPLICABILITY', 'applicability must be PREPAID_ONLY, POSTPAID_ONLY or ANY.');
        }

        $triggerType = $data['trigger_type'] ?? null;
        if ($triggerType !== null) {
            if (! in_array($triggerType, BillableEvent::TRIGGER_TYPES, true)) {
                throw DomainException::ruleRejected('INVALID_TRIGGER_TYPE', 'Unknown trigger_type.');
            }
            // R-BIL-CFG-01-T-2/T-3/T-6: each trigger model declares its firing key.
            if ($triggerType === 'SAGA_INTENT' && empty($data['trigger_intent_code'])) {
                throw DomainException::ruleRejected('TRIGGER_INTENT_REQUIRED', 'SAGA_INTENT events require trigger_intent_code.');
            }
            if ($triggerType === 'LIFECYCLE_EVENT' && empty($data['trigger_event_type'])) {
                throw DomainException::ruleRejected('TRIGGER_EVENT_REQUIRED', 'LIFECYCLE_EVENT events require trigger_event_type.');
            }
            if ($triggerType === 'LIFECYCLE_EVENT' && ! empty($data['state_callback'])) {
                throw DomainException::ruleRejected('CALLBACK_NOT_ALLOWED', 'LIFECYCLE_EVENT events must not carry a state_callback (the lifecycle already committed).');
            }
            if ($triggerType === 'SCHEDULED' && empty($data['trigger_schedule'])) {
                throw DomainException::ruleRejected('TRIGGER_SCHEDULE_REQUIRED', 'SCHEDULED events require a cron trigger_schedule.');
            }
        }

        // R-BIL-CFG-01-B-6 / SC-3: a state callback only fires after a confirmed
        // charge, so pay_first_required must be true.
        if (! empty($data['state_callback']) && array_key_exists('pay_first_required', $data) && ! $data['pay_first_required']) {
            throw DomainException::ruleRejected('PAY_FIRST_REQUIRED_FOR_STATE_CALLBACK', 'An event with a state_callback must be pay-first.');
        }

        // R-BIL-CFG-01-B-7: category must be an ACTIVE row of the SAME operator.
        if (isset($data['category_code'])) {
            $category = BillableEventCategory::query()
                ->where('operator_code', $operator)
                ->where('code', $data['category_code'])
                ->where('status', 'ACTIVE')->first();
            if (! $category) {
                throw DomainException::ruleRejected('CATEGORY_OPERATOR_MISMATCH', "Category '{$data['category_code']}' is not an ACTIVE category of this operator.");
            }
        }
    }

    private function emitChanged(BillableEvent $event, string $change): void
    {
        $this->events->publish(new DomainEvent(
            type: BillingEvents::BILLABLE_EVENT_CHANGED,
            topic: BillingEvents::TOPIC,
            payload: ['id' => $event->id, 'code' => $event->code, 'operatorCode' => $event->operator_code, 'change' => $change, 'status' => $event->status],
            aggregateType: 'BillableEvent',
            aggregateId: $event->id,
        ));
    }
}
