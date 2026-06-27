<?php

namespace Modules\Ilm\Services;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Rules\RuleEngine;
use App\Foundation\Support\Context;
use Modules\Ilm\Events\CvmEvents;
use Modules\Ilm\Models\CvmSegmentMembership;
use Modules\Ilm\Models\CvmSignalProfile;

/**
 * EM-03 evaluation (DD §3, §5.1). Refreshes a customer's signal profile from the supplied
 * signals (enriched with the live dunning level from BIL-04), runs rules.cvm.segmentation to
 * place the customer into a CVM segment with a churn-risk score, and — when the rule says so —
 * spawns a retention/recovery activity. Segmentation is rule-driven (operator-configurable
 * decision table), not hardcoded; the MVP is rules-based, not ML.
 */
class CvmEvaluationService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly RuleEngine $rules,
        private readonly CvmActivityService $activities,
    ) {}

    /**
     * @param array<string,mixed> $signals dunningLevel, complaintCount90d, daysSinceLastPayment, openTicketCount, activeSubscriptionCount
     * @return array{profile:CvmSignalProfile, segments:array<int,string>, activityId:?string}
     */
    public function evaluate(string $operator, string $customerId, array $signals = [], ?string $reason = null, ?string $sourceEventRef = null): array
    {
        Context::setOperatorCode($operator);

        $dunningLevel = $signals['dunningLevel'] ?? $this->liveDunningLevel($operator, $signals['accountId'] ?? null);

        $profile = CvmSignalProfile::query()->updateOrCreate(
            ['operator_code' => $operator, 'customer_id' => $customerId],
            [
                'account_id' => $signals['accountId'] ?? null,
                'active_subscription_count' => (int) ($signals['activeSubscriptionCount'] ?? 0),
                'open_ticket_count' => (int) ($signals['openTicketCount'] ?? 0),
                'dunning_level' => $dunningLevel,
                'days_since_last_payment' => $signals['daysSinceLastPayment'] ?? null,
                'complaint_count_90d' => (int) ($signals['complaintCount90d'] ?? 0),
                'last_evaluated_at' => now(),
            ],
        );

        // rules.cvm.segmentation: facts -> {segment, churnRiskScore, upsellScore, activityType, activityPriority}.
        $decision = $this->rules->evaluate('rules.cvm.segmentation', [
            'dunningLevel' => (int) ($dunningLevel ?? 0),
            'complaintCount90d' => $profile->complaint_count_90d,
            'daysSinceLastPayment' => (int) ($profile->days_since_last_payment ?? 0),
            'openTicketCount' => $profile->open_ticket_count,
            'activeSubscriptionCount' => $profile->active_subscription_count,
        ]);

        $profile->update([
            'churn_risk_score' => (float) ($decision['churnRiskScore'] ?? 0),
            'upsell_score' => (float) ($decision['upsellScore'] ?? 0),
        ]);

        $segments = [];
        if (! empty($decision['segment'])) {
            CvmSegmentMembership::query()->updateOrCreate(
                ['operator_code' => $operator, 'customer_id' => $customerId, 'segment_code' => $decision['segment']],
                ['status' => CvmSegmentMembership::ACTIVE, 'reason_code' => $reason ?? ($decision['reasonCode'] ?? null), 'entered_at' => now(), 'expires_at' => now()->addDays(30)],
            );
            $segments[] = $decision['segment'];
        }

        $activityId = null;
        if (! empty($decision['activityType'])) {
            $activity = $this->activities->create([
                'operatorCode' => $operator, 'activityType' => $decision['activityType'], 'customerId' => $customerId,
                'accountId' => $signals['accountId'] ?? null, 'subscriptionId' => $signals['subscriptionId'] ?? null,
                'sourceEventRef' => $sourceEventRef, 'triggerReason' => $reason,
                'assignedTeamId' => $decision['assignTeam'] ?? null, 'priority' => $decision['activityPriority'] ?? 'MEDIUM',
            ]);
            $activityId = $activity->activity_id;
        }

        $this->events->publish(new DomainEvent(
            type: CvmEvents::CUSTOMER_EVALUATED, topic: CvmEvents::TOPIC,
            payload: ['customerId' => $customerId, 'operatorCode' => $operator, 'churnRiskScore' => (string) $profile->churn_risk_score, 'segments' => $segments, 'reason' => $reason],
            aggregateType: 'CvmSignalProfile', aggregateId: $profile->signal_profile_id,
        ));

        return ['profile' => $profile->refresh(), 'segments' => $segments, 'activityId' => $activityId];
    }

    private function liveDunningLevel(string $operator, ?string $accountId): ?int
    {
        if (! $accountId || ! class_exists(\Modules\Billing\Dunning\Models\DunningState::class)) {
            return null;
        }
        $state = \Modules\Billing\Dunning\Models\DunningState::query()->where('operator_code', $operator)->where('account_id', $accountId)->first();

        return $state ? (int) $state->current_level : null;
    }
}
