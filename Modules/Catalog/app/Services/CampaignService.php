<?php

namespace Modules\Catalog\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Rules\RuleEngine;
use App\Foundation\Support\Id;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Events\CatalogEvents;
use Modules\Catalog\Models\CampaignParticipation;
use Modules\Catalog\Models\CommercialBundle;
use Modules\Catalog\Models\Discount;
use Modules\Catalog\Models\DiscountAssignment;
use Modules\Catalog\Models\PromoCampaign;

/**
 * SIP-05 promotional campaigns (MVP scope per the DD): lifecycle, channel/window
 * governance, target-rule eligibility, and per-participant redemption that binds
 * to a SIP-03 discount assignment. SIP-05 decides WHO is eligible and creates the
 * assignment request; PLM-CFG-04 defines amounts and DIS-OP/BIL apply them.
 */
class CampaignService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly RuleEngine $rules,
    ) {}

    /** Map of rule_type → the eligibility-context key it inspects. */
    private const RULE_CONTEXT = [
        'FRANCHISE' => 'franchiseId',
        'REGION' => 'regionCode',
        'PACKAGE' => 'packageRef',
        'CHANNEL' => 'channelCode',
        'CUSTOMER_SEGMENT' => 'customerSegment',
        'PAYMENT_STATUS' => 'paymentStatus',
    ];

    /** @param array<string,mixed> $data with offers[]?, target_rules[]?, channels[]? */
    public function create(array $data): PromoCampaign
    {
        return DB::transaction(function () use ($data) {
            $campaign = PromoCampaign::query()->create([
                'campaign_id' => Id::make('camp'),
                'code' => $data['code'],
                'name' => $data['name'],
                'campaign_type' => $data['campaign_type'] ?? 'ACQUISITION',
                'segment' => $data['segment'] ?? null,
                'status' => PromoCampaign::DRAFT,
                'starts_at' => $data['starts_at'] ?? now(),
                'ends_at' => $data['ends_at'] ?? null,
                'owner_team_code' => $data['owner_team_code'] ?? null,
                'budget_limit_amount' => $data['budget_limit_amount'] ?? null,
                'max_participants' => $data['max_participants'] ?? null,
                'created_by_user_id' => $data['created_by'] ?? null,
            ]);

            foreach ($data['offers'] ?? [] as $offer) {
                $campaign->offers()->create($offer);
            }
            foreach ($data['target_rules'] ?? [] as $i => $rule) {
                $campaign->targetRules()->create($rule + ['display_order' => $rule['display_order'] ?? $i]);
            }
            foreach ($data['channels'] ?? [] as $channel) {
                $campaign->channels()->create(is_array($channel) ? $channel : ['channel_code' => $channel]);
            }

            $this->emit(CatalogEvents::CAMPAIGN_CREATED, $campaign);

            return $campaign->refresh()->load('offers', 'targetRules', 'channels');
        });
    }

    /**
     * §7.4 launch validation — checks that gate activation (R-SIP-CAMP-02/03/04/05):
     * valid dates, at least one active offer (or a MESSAGE_ONLY purpose), discount offers
     * reference active PLM-CFG-04 discounts, and bundle offers reference active SIP-04
     * bundles. Returns findings; a FAIL blocks activation.
     *
     * @return array<int,array{check:string,status:string,message:string}>
     */
    public function validate(PromoCampaign $campaign): array
    {
        $offers = $campaign->offers()->where('status', 'ACTIVE')->get();

        // Operator-variable launch gates live in the rules engine (config, Rules Studio):
        // the service supplies facts, `rules.campaign.validation` decides what blocks launch.
        $assessed = $this->rules->assess('rules.campaign.validation', [
            'hasActiveOffer' => $offers->isNotEmpty(),
            'datesValid' => ! ($campaign->starts_at && $campaign->ends_at && $campaign->ends_at->lt($campaign->starts_at)),
            'campaignType' => $campaign->campaign_type,
        ]);
        $checks = array_map(
            fn ($e) => ['check' => $e['ruleId'] ?? 'RULE', 'status' => 'FAIL', 'message' => $e['message']],
            $assessed['validationErrors'],
        );

        // Fixed integrity checks (ref existence) stay in code (R-SIP-CAMP-04/05).
        foreach ($offers as $offer) {
            if ($offer->offer_type === 'DISCOUNT' && $offer->discount_code) {
                $ok = Discount::query()->where('operator_code', $campaign->operator_code)->where('code', $offer->discount_code)->where('status', 'ACTIVE')->exists();
                $checks[] = ['check' => 'DISCOUNT_ACTIVE', 'status' => $ok ? 'PASS' : 'FAIL', 'message' => $ok ? "Discount {$offer->discount_code} is active." : "Discount {$offer->discount_code} is missing or inactive."];
            }
            if ($offer->offer_type === 'BUNDLE' && $offer->bundle_code) {
                $ok = CommercialBundle::query()->where('operator_code', $campaign->operator_code)->where('bundle_code', $offer->bundle_code)->where('status', CommercialBundle::ACTIVE)->exists();
                $checks[] = ['check' => 'BUNDLE_ACTIVE', 'status' => $ok ? 'PASS' : 'FAIL', 'message' => $ok ? "Bundle {$offer->bundle_code} is active." : "Bundle {$offer->bundle_code} is missing or not ACTIVE."];
            }
        }

        return $checks;
    }

    public function activate(PromoCampaign $campaign): PromoCampaign
    {
        if (! in_array($campaign->status, [PromoCampaign::DRAFT, PromoCampaign::APPROVED, PromoCampaign::PAUSED], true)) {
            throw DomainException::conflict("Campaign is {$campaign->status}; cannot activate.");
        }
        // A PAUSED campaign was already validated on first activation; only gate fresh launches.
        if (in_array($campaign->status, [PromoCampaign::DRAFT, PromoCampaign::APPROVED], true)) {
            $failures = collect($this->validate($campaign))->where('status', 'FAIL');
            if ($failures->isNotEmpty()) {
                throw DomainException::ruleRejected('CAMPAIGN_VALIDATION_FAILED', $failures->pluck('message')->implode(' '));
            }
        }
        $campaign->update(['status' => PromoCampaign::ACTIVE]);
        $this->emit(CatalogEvents::CAMPAIGN_ACTIVATED, $campaign);

        return $campaign->refresh();
    }

    public function pause(PromoCampaign $campaign): PromoCampaign
    {
        if ($campaign->status !== PromoCampaign::ACTIVE) {
            throw DomainException::conflict("Campaign is {$campaign->status}; only ACTIVE can be paused.");
        }
        $campaign->update(['status' => PromoCampaign::PAUSED]);

        return $campaign->refresh();
    }

    public function end(PromoCampaign $campaign): PromoCampaign
    {
        if (! in_array($campaign->status, [PromoCampaign::ACTIVE, PromoCampaign::PAUSED], true)) {
            throw DomainException::conflict("Campaign is {$campaign->status}; cannot end.");
        }
        $campaign->update(['status' => PromoCampaign::ENDED, 'ends_at' => now()]);

        return $campaign->refresh();
    }

    /**
     * §7 eligibility: status + window + channel governance + target rules. A failed
     * rule with hard_exclusion blocks; soft failures are returned as warnings.
     *
     * @param  array<string,mixed>  $context  channelCode, franchiseId?, regionCode?, packageRef?, customerSegment?, ...
     * @return array{eligible:bool, reasons:array<int,string>, warnings:array<int,string>}
     */
    public function checkEligibility(PromoCampaign $campaign, array $context): array
    {
        $warnings = [];

        // Per-campaign targeting (config rows in promotion_campaign_target_rule). A hard
        // failure becomes a fact; a soft failure is surfaced as a warning.
        $hardFailures = [];
        foreach ($campaign->targetRules()->where('status', 'ACTIVE')->orderBy('display_order')->get() as $rule) {
            if ($this->rulePasses($rule->rule_type, $rule->operator, $rule->rule_value_json, $context)) {
                continue;
            }
            $rule->hard_exclusion ? $hardFailures[] = "RULE_FAILED:{$rule->rule_type}" : $warnings[] = "RULE_FAILED:{$rule->rule_type}";
        }

        // The operator-variable eligibility gates are decided by the rules engine
        // (`rules.campaign.eligibility`): the service only supplies facts. Operators
        // add/remove a gate in the Rules Studio without a code change.
        $now = now();
        $channels = $campaign->channels()->where('status', 'ACTIVE')->pluck('channel_code');
        $assessed = $this->rules->assess('rules.campaign.eligibility', [
            'active' => $campaign->status === PromoCampaign::ACTIVE,
            'withinWindow' => (! $campaign->starts_at || $now->gte($campaign->starts_at)) && (! $campaign->ends_at || $now->lte($campaign->ends_at)),
            'channelAllowed' => $channels->isEmpty() || $channels->contains($context['channelCode'] ?? null),
            'capReached' => $campaign->max_participants !== null
                && $campaign->participations()->whereNotIn('status', ['REJECTED', 'CANCELLED'])->count() >= $campaign->max_participants,
            'hardRuleFailed' => $hardFailures !== [],
            'campaignType' => $campaign->campaign_type,
            'channelCode' => $context['channelCode'] ?? null,
        ]);
        $reasons = array_values(array_map(fn ($e) => $e['message'], $assessed['validationErrors']));

        return ['eligible' => $reasons === [], 'reasons' => $reasons, 'warnings' => $warnings];
    }

    /**
     * §6.5 participate/redeem: one participation per participant (unique), and a
     * DISCOUNT offer creates the SIP-03 assignment the order/billing flow applies.
     *
     * @param  array<string,mixed>  $context
     */
    public function participate(PromoCampaign $campaign, string $participantType, string $participantRef, array $context = []): CampaignParticipation
    {
        $check = $this->checkEligibility($campaign, $context);
        if (! $check['eligible']) {
            throw DomainException::ruleRejected('CAMPAIGN_NOT_ELIGIBLE', implode(', ', $check['reasons']));
        }
        if ($campaign->participations()->where('participant_type', $participantType)->where('participant_ref_id', $participantRef)->exists()) {
            throw DomainException::conflict('Participant has already redeemed this campaign.'); // duplicate-redemption guard
        }

        return DB::transaction(function () use ($campaign, $participantType, $participantRef, $context) {
            $participation = $campaign->participations()->create([
                'operator_code' => $campaign->operator_code,
                'participant_type' => $participantType,
                'participant_ref_id' => $participantRef,
                'customer_id' => $context['customerId'] ?? null,
                'franchise_id' => $context['franchiseId'] ?? null,
                'agent_user_id' => $context['agentUserId'] ?? null,
                'status' => 'SELECTED',
            ]);

            // Bind the highest-priority ACTIVE discount offer to a SIP-03 assignment.
            $offer = $campaign->offers()->where('status', 'ACTIVE')->where('offer_type', 'DISCOUNT')->orderBy('priority')->first();
            if ($offer && $offer->discount_code) {
                $assignment = DiscountAssignment::query()->create([
                    'assignment_id' => Id::make('dasg'),
                    'operator_code' => $campaign->operator_code,
                    'discount_code' => $offer->discount_code,
                    'scope' => $offer->assignment_scope_type ?? 'CAMPAIGN',
                    'scope_ref' => $participantRef,
                    'campaign_code' => $campaign->code,
                    'active' => true,
                ]);
                $participation->update(['status' => 'REDEEMED', 'assignment_id' => $assignment->assignment_id, 'redeemed_at' => now()]);
            } else {
                $participation->update(['status' => 'REDEEMED', 'redeemed_at' => now()]);
            }

            $this->emit(CatalogEvents::CAMPAIGN_REDEEMED, $campaign, [
                'participationId' => $participation->participation_id,
                'participantType' => $participantType,
                'participantRef' => $participantRef,
            ]);

            return $participation->refresh();
        });
    }

    /** @param array<string,mixed> $values */
    private function rulePasses(string $ruleType, string $operator, array $values, array $context): bool
    {
        $key = self::RULE_CONTEXT[$ruleType] ?? null;
        if (! $key) {
            return true; // unknown/custom rule types are advisory in the MVP
        }
        $actual = $context[$key] ?? null;
        $expected = collect($values)->flatten()->all();

        return match ($operator) {
            'IN', 'EQ' => in_array($actual, $expected, true),
            'NOT_IN' => ! in_array($actual, $expected, true),
            default => true,
        };
    }

    /** @param array<string,mixed> $extra */
    private function emit(string $type, PromoCampaign $campaign, array $extra = []): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: CatalogEvents::TOPIC,
            payload: array_merge(['campaignId' => $campaign->campaign_id, 'campaignCode' => $campaign->code, 'status' => $campaign->status], $extra),
            aggregateType: 'PromoCampaign',
            aggregateId: $campaign->campaign_id,
        ));
    }
}
