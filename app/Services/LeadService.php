<?php

namespace App\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Id;
use App\Models\SalesLead;
use Illuminate\Support\Facades\DB;
use Modules\Ilm\Models\Customer;

/**
 * SALES-01 lead funnel. Captures a lead with franchise/agent attribution and moves
 * it NEW -> QUALIFIED -> CONVERTED (creating an ILM customer, preserving the
 * attribution) or LOST. Conversion is the hand-off point to fulfillment.
 */
class LeadService
{
    public function __construct(private readonly EventBus $events) {}

    /** @param array<string,mixed> $data */
    public function capture(array $data): SalesLead
    {
        // SALES-01 territory routing: a lead with a territory but no explicit
        // franchise is attributed to the franchise covering that territory.
        if (empty($data['franchise_code']) && ! empty($data['territory'])) {
            $franchise = \App\Models\Franchise::query()
                ->where('operator_code', $data['operator_code'] ?? \App\Foundation\Support\Context::operatorCode())
                ->where('territory', $data['territory'])->where('status', 'ACTIVE')->first();
            if ($franchise) {
                $data['franchise_code'] = $franchise->code;
            }
        }

        $lead = SalesLead::query()->create($data + ['lead_id' => Id::make('lead'), 'status' => 'NEW']);
        $this->emit($lead, 'SalesLeadCaptured');

        return $lead;
    }

    public function qualify(SalesLead $lead): SalesLead
    {
        if ($lead->status !== 'NEW') {
            throw DomainException::conflict('Only NEW leads can be qualified.');
        }
        $lead->update(['status' => 'QUALIFIED']);
        $this->emit($lead, 'SalesLeadQualified');

        return $lead;
    }

    public function convert(SalesLead $lead): SalesLead
    {
        if (! in_array($lead->status, ['NEW', 'QUALIFIED'], true)) {
            throw DomainException::conflict('Lead cannot be converted from its current status.');
        }

        return DB::transaction(function () use ($lead) {
            $customer = Customer::query()->create([
                'customer_id' => Id::make('cust'),
                'operator_code' => $lead->operator_code,
                'type' => 'RES',
                'name' => $lead->name,
                'primary_msisdn' => $lead->msisdn,
            ]);
            $lead->update(['status' => 'CONVERTED', 'converted_customer_id' => $customer->customer_id]);
            $this->emit($lead, 'SalesLeadConverted', ['customerId' => $customer->customer_id, 'franchiseCode' => $lead->franchise_code]);

            return $lead->refresh();
        });
    }

    public function lose(SalesLead $lead, ?string $reason): SalesLead
    {
        $lead->update(['status' => 'LOST', 'lost_reason' => $reason]);
        $this->emit($lead, 'SalesLeadLost');

        return $lead;
    }

    private function emit(SalesLead $lead, string $type, array $extra = []): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: 'sales.lead',
            payload: array_merge(['leadId' => $lead->lead_id, 'status' => $lead->status, 'franchiseCode' => $lead->franchise_code, 'agent' => $lead->assigned_agent], $extra),
            aggregateType: 'SalesLead',
            aggregateId: $lead->lead_id,
        ));
    }
}
