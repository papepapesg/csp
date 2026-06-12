<?php

namespace App\Services;

use App\Events\SalesEvents;
use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use App\Models\SalesActivity;
use App\Models\SalesAttributionEvent;
use App\Models\SalesConversion;
use App\Models\SalesLead;
use App\Models\SalesLeadAssignment;
use App\Models\SalesTerritory;
use Illuminate\Support\Facades\DB;
use Modules\Fulfillment\Services\OrderCaptureService;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Models\CustomerAccount;

/**
 * SALES-01 commercial pre-order pipeline. Owns leads, territory routing, assignment history,
 * activities, and lead→FUL-02 conversion with immutable attribution events. It is NOT a CRM for
 * orders (FUL-02 owns those) nor a customer master (ILM owns that) — it stores references after
 * conversion. Conversion is transactional on the SALES side; if the FUL-02 order fails the lead
 * stays QUALIFIED with the failure detail (SALES-2).
 */
class SalesService
{
    public function __construct(private readonly EventBus $events) {}

    /** @param array<string,mixed> $data */
    public function createLead(array $data): SalesLead
    {
        $operator = $data['operatorCode'] ?? Context::operatorCode();
        Context::setOperatorCode($operator);
        $phone = $data['primaryPhone'] ?? $data['msisdn'] ?? null;

        // SALES-1: duplicate active lead by phone/homepass; SALES-7: existing customer.
        $duplicateRisk = $this->assessDuplicate($operator, $phone, $data['homepassId'] ?? null);

        // Territory routing: resolve a territory code to its franchise owner.
        $territory = isset($data['territoryCode']) ? SalesTerritory::resolve($operator, $data['territoryCode']) : null;

        $lead = SalesLead::query()->create([
            'operator_code' => $operator,
            'lead_number' => $this->nextLeadNumber($operator),
            'name' => $data['prospectName'] ?? $data['name'] ?? 'Prospect',
            'prospect_name' => $data['prospectName'] ?? $data['name'] ?? null,
            'msisdn' => $phone, 'primary_phone' => $phone,
            'source' => $data['sourceChannel'] ?? $data['source'] ?? null,
            'source_channel' => $data['sourceChannel'] ?? null,
            'homepass_id' => $data['homepassId'] ?? null, 'package_ref' => $data['packageRef'] ?? null,
            'territory' => $data['territoryCode'] ?? null, 'territory_id' => $territory?->territory_id,
            'franchise_code' => $data['franchiseCode'] ?? $territory?->franchise_contractor_id,
            'created_by_agent_id' => $data['createdByAgentId'] ?? null, 'assigned_agent' => $data['createdByAgentId'] ?? null,
            'geo_lat' => $data['geo']['lat'] ?? null, 'geo_lng' => $data['geo']['lng'] ?? null,
            'consent_captured' => (bool) ($data['consentCaptured'] ?? false),
            'duplicate_risk' => $duplicateRisk,
            'status' => SalesLead::NEW,
        ]);

        foreach ($data['packageInterest'] ?? [] as $pi) {
            DB::table('sales_lead_package_interest')->insert([
                'interest_id' => Id::make('spi'), 'lead_id' => $lead->lead_id, 'operator_code' => $operator,
                'package_id' => $pi['packageId'], 'priority' => $pi['priority'] ?? 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Auto-assign by territory default if an agent was provided.
        if (! empty($data['createdByAgentId'])) {
            $this->assign($lead, ['assignedAgentId' => $data['createdByAgentId'], 'reasonCode' => 'TERRITORY_DEFAULT'], silent: true);
        }

        $this->emit(SalesEvents::LEAD_CREATED, $lead, ['leadNumber' => $lead->lead_number, 'duplicateRisk' => $duplicateRisk]);

        return $lead->refresh();
    }

    /** @param array<string,mixed> $data */
    public function assign(SalesLead $lead, array $data, bool $silent = false): SalesLead
    {
        if ($lead->status === SalesLead::CONVERTED) {
            throw DomainException::conflict('A converted lead cannot be reassigned (SALES-6).');
        }
        DB::transaction(function () use ($lead, $data) {
            SalesLeadAssignment::query()->where('lead_id', $lead->lead_id)->where('active', true)->update(['active' => false]);
            SalesLeadAssignment::query()->create([
                'assignment_id' => Id::make('assn'), 'lead_id' => $lead->lead_id, 'operator_code' => $lead->operator_code,
                'assigned_agent_id' => $data['assignedAgentId'] ?? null, 'assigned_team_id' => $data['assignedTeamId'] ?? null,
                'assigned_by_user_id' => $data['assignedByUserId'] ?? null, 'reason_code' => $data['reasonCode'] ?? 'MANUAL',
                'active' => true, 'assigned_at' => now(),
            ]);
            $lead->update([
                'assigned_agent' => $data['assignedAgentId'] ?? $lead->assigned_agent,
                'status' => $lead->status === SalesLead::NEW ? SalesLead::ASSIGNED : $lead->status,
            ]);
        });
        if (! $silent) {
            $this->emit(SalesEvents::LEAD_ASSIGNED, $lead->refresh(), ['agentId' => $data['assignedAgentId'] ?? null, 'teamId' => $data['assignedTeamId'] ?? null]);
        }

        return $lead->refresh();
    }

    /** @param array<string,mixed> $data */
    public function addActivity(SalesLead $lead, array $data): SalesActivity
    {
        $activity = SalesActivity::query()->create([
            'activity_id' => Id::make('sact'), 'lead_id' => $lead->lead_id, 'operator_code' => $lead->operator_code,
            'agent_id' => $data['agentId'] ?? null, 'activity_type' => $data['activityType'],
            'outcome_code' => $data['outcomeCode'] ?? null, 'notes' => $data['notes'] ?? null,
            'next_follow_up_at' => $data['nextFollowUpAt'] ?? null, 'created_at' => now(),
        ]);
        // A contact outcome advances the lead state where the transition is legal.
        $next = match ($data['outcomeCode'] ?? null) {
            'INTERESTED' => SalesLead::CONTACTED,
            'FOLLOW_UP' => SalesLead::FOLLOW_UP,
            'NOT_INTERESTED' => SalesLead::CONTACTED,
            default => null,
        };
        if ($next && $this->canTransition($lead->status, $next)) {
            $lead->update(['status' => $next]);
        }

        return $activity;
    }

    public function qualify(SalesLead $lead): SalesLead
    {
        if (! $this->canTransition($lead->status, SalesLead::QUALIFIED)) {
            throw DomainException::conflict("A {$lead->status} lead cannot be qualified.");
        }
        $lead->update(['status' => SalesLead::QUALIFIED]);
        $this->emit(SalesEvents::LEAD_QUALIFIED, $lead);

        return $lead->refresh();
    }

    /**
     * Convert a qualified lead to a FUL-02 order. Creates the ILM customer + account, calls the
     * FUL-02 order capture, writes the conversion link + immutable attribution event. If FUL
     * order creation fails, the lead stays QUALIFIED (SALES-2).
     *
     * @param array<string,mixed> $data
     */
    public function convertToOrder(SalesLead $lead, array $data): SalesConversion
    {
        if ($lead->status !== SalesLead::QUALIFIED && $lead->status !== SalesLead::NEW) {
            throw DomainException::conflict('Only a NEW/QUALIFIED lead can be converted.');
        }
        $packageRef = $data['selectedPackageId'] ?? $lead->package_ref;
        if (! $packageRef) {
            throw DomainException::ruleRejected('NO_PACKAGE_SELECTED', 'A package must be selected to convert.');
        }

        return DB::transaction(function () use ($lead, $data, $packageRef) {
            $customer = Customer::query()->create([
                'customer_id' => Id::make('cust'), 'operator_code' => $lead->operator_code, 'type' => 'RES',
                'name' => $data['customerDraft']['fullName'] ?? $lead->prospect_name ?? $lead->name,
                'primary_msisdn' => $data['customerDraft']['primaryPhone'] ?? $lead->primary_phone ?? $lead->msisdn,
            ]);
            $account = CustomerAccount::query()->create([
                'account_id' => Id::make('acct'), 'customer_id' => $customer->customer_id, 'operator_code' => $lead->operator_code,
                'account_number' => 'A-'.strtoupper(substr($customer->customer_id, -8)), 'service_address' => $data['serviceAddress'] ?? 'TBD',
            ]);

            // FUL-02 owns the order. A failure here rolls back and the lead stays QUALIFIED.
            $order = app(OrderCaptureService::class)->capture([
                'customer_id' => $customer->customer_id, 'account_id' => $account->account_id,
                'homepass_id' => $lead->homepass_id, 'package_ref' => $packageRef,
            ]);

            $conversion = SalesConversion::query()->create([
                'conversion_id' => Id::make('conv'), 'lead_id' => $lead->lead_id, 'operator_code' => $lead->operator_code,
                'order_id' => $order->getKey(), 'customer_id' => $customer->customer_id,
                'converted_by_agent_id' => $data['salesAttribution']['agentId'] ?? $lead->assigned_agent, 'converted_at' => now(),
            ]);

            $lead->update(['status' => SalesLead::CONVERTED, 'converted_customer_id' => $customer->customer_id]);
            $this->addActivity($lead, ['agentId' => $conversion->converted_by_agent_id, 'activityType' => 'CONVERSION', 'outcomeCode' => 'CONVERTED']);

            // SALES-5: immutable attribution event = the commission basis.
            $attr = $data['salesAttribution'] ?? [];
            SalesAttributionEvent::query()->create([
                'attribution_event_id' => Id::make('satt'), 'operator_code' => $lead->operator_code, 'lead_id' => $lead->lead_id,
                'order_id' => $order->getKey(), 'agent_id' => $attr['agentId'] ?? $lead->assigned_agent, 'team_id' => $attr['teamId'] ?? null,
                'franchise_contractor_id' => $attr['franchiseContractorId'] ?? $lead->franchise_code, 'territory_code' => $attr['territoryCode'] ?? $lead->territory,
                'event_type' => 'LEAD_CONVERTED', 'payload_json' => ['packageRef' => $packageRef], 'occurred_at' => now(),
            ]);

            $this->emit(SalesEvents::LEAD_CONVERTED, $lead, ['customerId' => $customer->customer_id, 'orderId' => $order->getKey(), 'franchiseCode' => $lead->franchise_code]);
            $this->emit(SalesEvents::ATTRIBUTION_RECORDED, $lead, ['orderId' => $order->getKey(), 'agentId' => $attr['agentId'] ?? $lead->assigned_agent]);

            return $conversion;
        });
    }

    public function lose(SalesLead $lead, ?string $reason): SalesLead
    {
        $lead->update(['status' => SalesLead::LOST, 'lost_reason' => $reason]);
        $this->emit(SalesEvents::LEAD_LOST, $lead, ['reason' => $reason]);

        return $lead->refresh();
    }

    /** FE-APP-02 agent daily-work list: the agent's active assigned, non-terminal leads. */
    public function dailyWork(string $operator, string $agentId): \Illuminate\Support\Collection
    {
        return SalesLead::query()->where('operator_code', $operator)->where('assigned_agent', $agentId)
            ->whereNotIn('status', [SalesLead::CONVERTED, SalesLead::LOST, SalesLead::CANCELLED, SalesLead::DUPLICATE])
            ->orderByDesc('created_at')->get();
    }

    private function assessDuplicate(string $operator, ?string $phone, ?string $homepassId): string
    {
        if ($phone && Customer::query()->where('operator_code', $operator)->where('primary_msisdn', $phone)->exists()) {
            return 'POSSIBLE_CUSTOMER';
        }
        $q = SalesLead::query()->where('operator_code', $operator)->whereNotIn('status', [SalesLead::CONVERTED, SalesLead::LOST, SalesLead::CANCELLED]);
        if (($phone && (clone $q)->where('primary_phone', $phone)->exists()) || ($homepassId && (clone $q)->where('homepass_id', $homepassId)->exists())) {
            return 'POSSIBLE_LEAD';
        }

        return 'NONE';
    }

    private function canTransition(string $from, string $to): bool
    {
        return in_array($to, SalesLead::TRANSITIONS[$from] ?? [], true);
    }

    private function nextLeadNumber(string $operator): string
    {
        $year = (int) now()->format('Y');
        DB::table('invoice_sequence')->insertOrIgnore(['operator_code' => $operator, 'type' => 'SALES_LEAD', 'fiscal_year' => $year, 'last_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('invoice_sequence')->where('operator_code', $operator)->where('type', 'SALES_LEAD')->where('fiscal_year', $year)->lockForUpdate()->first();
        $next = ((int) $row->last_value) + 1;
        DB::table('invoice_sequence')->where('operator_code', $operator)->where('type', 'SALES_LEAD')->where('fiscal_year', $year)->update(['last_value' => $next, 'updated_at' => now()]);

        return sprintf('SL-%s-%d-%06d', $operator, $year, $next);
    }

    /** @param array<string,mixed> $extra */
    private function emit(string $type, SalesLead $lead, array $extra = []): void
    {
        $this->events->publish(new DomainEvent(
            type: $type, topic: SalesEvents::TOPIC,
            payload: ['leadId' => $lead->lead_id, 'operatorCode' => $lead->operator_code, 'status' => $lead->status, 'franchiseCode' => $lead->franchise_code] + $extra,
            aggregateType: 'SalesLead', aggregateId: $lead->lead_id,
        ));
    }
}
