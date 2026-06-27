<?php

namespace Modules\Ilm\Services;

use Illuminate\Support\Facades\Log;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Models\CustomerAccount;
use Modules\Ilm\Models\CustomerAccountFlag;
use Modules\Ilm\Models\CustomerInteraction;
use Modules\Ilm\Models\CustomerNote;

/**
 * Customer 360 read composition. The 360 screen aggregates data from ILM, SUB, BIL and TCK.
 * Each panel resolves INDEPENDENTLY: a failure in one module's read degrades only that panel
 * (returns available=false + error) and never breaks the rest of the screen. This is the
 * server-side equivalent of the BO UI's per-panel fetches, in one round trip.
 */
class CustomerOverviewService
{
    /**
     * @return array{customerId:string, panels:array<string,array<string,mixed>>}
     */
    public function overview(string $customerId): array
    {
        $accountIds = fn () => CustomerAccount::query()->where('customer_id', $customerId)->pluck('account_id')->all();

        return [
            'customerId' => $customerId,
            'panels' => $this->compose([
                'profile' => fn () => $this->profile($customerId),
                'accounts' => fn () => $this->accounts($customerId),
                'subscriptions' => fn () => $this->subscriptions($customerId),
                'billing' => fn () => $this->billing($accountIds()),
                'tickets' => fn () => $this->tickets($customerId),
                'interactions' => fn () => $this->interactions($customerId),
                'notes' => fn () => $this->notes($customerId),
            ]),
        ];
    }

    /**
     * Run each named panel resolver in isolation. A throwing panel is logged and returned as
     * unavailable so the rest of the composition still succeeds.
     *
     * @param array<string,callable():mixed> $panels
     * @return array<string,array<string,mixed>>
     */
    public function compose(array $panels): array
    {
        $out = [];
        foreach ($panels as $name => $resolver) {
            try {
                $out[$name] = ['available' => true, 'data' => $resolver()];
            } catch (\Throwable $e) {
                Log::warning('customer_overview.panel_failed', ['panel' => $name, 'error' => $e->getMessage()]);
                $out[$name] = ['available' => false, 'error' => 'PANEL_UNAVAILABLE'];
            }
        }

        return $out;
    }

    private function profile(string $customerId): array
    {
        $c = Customer::query()->findOrFail($customerId);

        return [
            'customerId' => $c->customer_id, 'name' => $c->name, 'type' => $c->type,
            'msisdn' => $c->primary_msisdn, 'email' => $c->email ?? null,
            'preferredLanguage' => $c->preferred_language ?? null,
            'kycStatus' => $c->kyc_status ?? null,
            'createdAt' => $c->created_at?->toIso8601String(),
        ];
    }

    private function accounts(string $customerId): array
    {
        return CustomerAccount::query()->where('customer_id', $customerId)->get()->map(fn ($a) => [
            'accountId' => $a->account_id, 'accountNumber' => $a->account_number, 'status' => $a->status ?? null,
            'subStatus' => $a->sub_status ?? null, 'serviceAddress' => $a->service_address ?? null,
            'homepassId' => $a->homepass_id ?? null, 'attentionBanner' => $a->attention_banner ?? null,
            'installDate' => optional($a->install_date)->toDateString(),
            'startBillDate' => optional($a->start_bill_date)->toDateString(),
            'flags' => CustomerAccountFlag::query()->where('account_id', $a->account_id)->where('state', 'ACTIVE')->pluck('flag_code'),
        ])->all();
    }

    private function subscriptions(string $customerId): array
    {
        return \Modules\Subscription\Models\Subscription::query()->where('customer_id', $customerId)
            ->get()->map(fn ($s) => [
                'subscriptionId' => $s->subscription_id, 'package' => $s->package_ref, 'status' => $s->status_code,
                'billingMode' => $s->billing_mode ?? null, 'homepassId' => $s->homepass_id ?? null,
                'createdAt' => $s->created_at?->toIso8601String(),
            ])->all();
    }

    private function billing(array $accountIds): array
    {
        $q = \Modules\Billing\Invoicing\Models\Invoice::query()->whereIn('account_id', $accountIds);

        return [
            'balanceDue' => (float) (clone $q)->sum('amount_due'),
            'recentInvoices' => (clone $q)->orderByDesc('created_at')->limit(5)->get()
                ->map(fn ($i) => ['number' => $i->legal_invoice_number ?? $i->invoice_id, 'due' => (float) $i->amount_due, 'status' => $i->status])->all(),
        ];
    }

    private function tickets(string $customerId): array
    {
        return \Modules\Ticketing\Models\Ticket::query()->where('customer_id', $customerId)
            ->orderByDesc('created_at')->limit(10)->get()
            ->map(fn ($t) => ['number' => $t->ticket_number ?? $t->getKey(), 'subject' => $t->subject, 'status' => $t->status, 'priority' => $t->priority, 'at' => $t->created_at?->toIso8601String()])->all();
    }

    private function interactions(string $customerId): array
    {
        return CustomerInteraction::query()->where('customer_id', $customerId)->orderByDesc('created_at')->limit(10)->get()
            ->map(fn ($i) => ['reason' => $i->reason, 'agent' => $i->agent_name, 'at' => $i->created_at?->toIso8601String()])->all();
    }

    private function notes(string $customerId): array
    {
        return CustomerNote::query()->where('customer_id', $customerId)->orderByDesc('created_at')->limit(10)->get()
            ->map(fn ($n) => ['body' => $n->body, 'author' => $n->author_id ?? null, 'at' => $n->created_at?->toIso8601String()])->all();
    }
}
