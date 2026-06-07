<?php

namespace Modules\Ilm\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Rules\RuleEngine;
use App\Foundation\Support\Id;
use Modules\Ilm\Models\CvmActivity;

/**
 * EM-03 CVM engine. createActivity() resolves an offer via rules.cvm.offer for the
 * trigger + customer segment and records an OFFERED activity; accept()/decline()
 * close it. Triggered by churn signals (non-payment, downgrade) or campaigns.
 */
class CvmService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly RuleEngine $rules,
    ) {}

    /** @param array<string,mixed> $data customer_id, subscription_id?, type, trigger_reason?, segment?, channel? */
    public function createActivity(array $data): CvmActivity
    {
        $offer = $this->rules->evaluate('rules.cvm.offer', [
            'type' => $data['type'],
            'triggerReason' => $data['trigger_reason'] ?? null,
            'segment' => $data['segment'] ?? 'STANDARD',
        ]);

        $activity = CvmActivity::query()->create([
            'activity_id' => Id::make('cvm'),
            'customer_id' => $data['customer_id'],
            'subscription_id' => $data['subscription_id'] ?? null,
            'type' => $data['type'],
            'trigger_reason' => $data['trigger_reason'] ?? null,
            'offer_code' => $offer['offerCode'] ?? null,
            'offer_details' => $offer['offerDetails'] ?? null,
            'channel' => $data['channel'] ?? 'OUTBOUND_CALL',
            'status' => CvmActivity::OFFERED,
            'expires_at' => now()->addDays((int) ($offer['validDays'] ?? 14)),
        ]);
        $this->emit($activity, 'CvmOfferMade');

        return $activity;
    }

    public function decide(CvmActivity $activity, bool $accept, ?string $reason = null): CvmActivity
    {
        if ($activity->status !== CvmActivity::OFFERED) {
            throw DomainException::conflict('CVM activity is not open.');
        }
        $activity->update([
            'status' => $accept ? CvmActivity::ACCEPTED : CvmActivity::DECLINED,
            'outcome_reason' => $reason,
            'decided_at' => now(),
        ]);
        $this->emit($activity, $accept ? 'CvmOfferAccepted' : 'CvmOfferDeclined');

        return $activity->refresh();
    }

    private function emit(CvmActivity $activity, string $type): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: 'cvm.activity',
            payload: ['activityId' => $activity->activity_id, 'customerId' => $activity->customer_id, 'type' => $activity->type, 'offerCode' => $activity->offer_code, 'status' => $activity->status],
            aggregateType: 'CvmActivity',
            aggregateId: $activity->activity_id,
        ));
    }
}
