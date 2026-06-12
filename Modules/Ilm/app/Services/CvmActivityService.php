<?php

namespace Modules\Ilm\Services;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Modules\Ilm\Events\CvmEvents;
use Modules\Ilm\Models\CvmActivity;
use Modules\Ilm\Models\CvmOutcome;
use Modules\Ilm\Models\CustomerInteraction;

/**
 * EM-03 activity lifecycle (DD §4.3). An activity is an outreach task assigned to a user/team.
 * Creation is idempotent by source_event_ref (so a repeated dunning/churn event doesn't spawn
 * duplicate tasks). Every contact attempt writes to CUST-INT-01 (the customer interaction
 * timeline); closing an activity records a cvm_outcome. EM-03 owns the task, not the action —
 * the action (discount/resume/notify) is the owning module's job.
 */
class CvmActivityService
{
    public function __construct(private readonly EventBus $events) {}

    /** @param array<string,mixed> $data */
    public function create(array $data): CvmActivity
    {
        $operator = $data['operatorCode'] ?? Context::operatorCode();
        Context::setOperatorCode($operator);

        $ref = $data['sourceEventRef'] ?? null;
        if ($ref) {
            $existing = CvmActivity::query()->where('operator_code', $operator)->where('source_event_ref', $ref)->first();
            if ($existing) {
                return $existing; // idempotent by source event
            }
        }

        $activity = CvmActivity::query()->create([
            'operator_code' => $operator,
            'activity_type' => $data['activityType'],
            'customer_id' => $data['customerId'],
            'account_id' => $data['accountId'] ?? null,
            'subscription_id' => $data['subscriptionId'] ?? null,
            'source_event_ref' => $ref,
            'trigger_reason' => $data['triggerReason'] ?? null,
            'assigned_to_user_id' => $data['assignedToUserId'] ?? null,
            'assigned_team_id' => $data['assignedTeamId'] ?? null,
            'priority' => $data['priority'] ?? 'MEDIUM',
            'status' => CvmActivity::OPEN,
            'due_at' => isset($data['dueInHours']) ? now()->addHours((int) $data['dueInHours']) : null,
        ]);

        $this->writeInteraction($activity->customer_id, 'CVM_'.$activity->activity_type, "CVM activity {$activity->activity_id} created");
        $this->emit(CvmEvents::ACTIVITY_CREATED, $activity, ['activityType' => $activity->activity_type, 'priority' => $activity->priority]);

        return $activity;
    }

    public function transition(CvmActivity $activity, string $status): CvmActivity
    {
        $activity->update(['status' => $status]);

        return $activity->refresh();
    }

    /** Close the activity and record its outcome (DD §4.5). */
    public function close(CvmActivity $activity, string $outcomeCode, ?string $notes = null, ?string $offerInstanceId = null): CvmOutcome
    {
        $activity->update(['status' => CvmActivity::COMPLETED, 'closed_at' => now(), 'outcome_reason' => $outcomeCode]);

        $outcome = CvmOutcome::query()->create([
            'operator_code' => $activity->operator_code,
            'activity_id' => $activity->activity_id,
            'offer_instance_id' => $offerInstanceId,
            'customer_id' => $activity->customer_id,
            'outcome_code' => $outcomeCode,
            'notes' => $notes,
        ]);

        $this->writeInteraction($activity->customer_id, 'CVM_OUTCOME', "Activity closed: {$outcomeCode}");
        $this->emit(CvmEvents::ACTIVITY_CLOSED, $activity, ['outcomeCode' => $outcomeCode]);

        return $outcome;
    }

    /** CUST-INT-01: every customer contact attempt lands on the interaction timeline. */
    public function writeInteraction(string $customerId, string $reason, ?string $findings = null): void
    {
        CustomerInteraction::query()->create([
            'id' => Id::make('int'),
            'customer_id' => $customerId,
            'agent_name' => 'cvm-engine',
            'reason' => $reason,
            'findings' => $findings,
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function emit(string $type, CvmActivity $activity, array $extra = []): void
    {
        $this->events->publish(new DomainEvent(
            type: $type, topic: CvmEvents::TOPIC,
            payload: ['activityId' => $activity->activity_id, 'customerId' => $activity->customer_id, 'operatorCode' => $activity->operator_code] + $extra,
            aggregateType: 'CvmActivity', aggregateId: $activity->activity_id,
        ));
    }
}
