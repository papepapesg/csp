<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * FE-APP-01 §16 federated global search. Fans a query across the customer/account/subscription/
 * invoice/payment/work-order/equipment/ticket entity types and returns grouped hits. Each source
 * resolves in isolation (a failing module yields no group, never an error) and is operator-scoped.
 * Read-only BFF; deep-link routing is decided client-side per type.
 */
class GlobalSearchService
{
    private const LIMIT = 6;

    /** @return array<int,array{type:string,label:string,items:array<int,array<string,mixed>>}> */
    public function search(string $q, ?string $operator): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%'.$q.'%';

        $sources = [
            ['customer', 'Customers', fn () => $this->customers($like, $operator)],
            ['account', 'Accounts', fn () => $this->accounts($like, $operator)],
            ['subscription', 'Subscriptions', fn () => $this->subscriptions($like, $operator)],
            ['invoice', 'Invoices', fn () => $this->invoices($like, $operator)],
            ['payment', 'Payments', fn () => $this->payments($like, $operator)],
            ['workorder', 'Work orders', fn () => $this->workOrders($like, $operator)],
            ['equipment', 'Equipment', fn () => $this->equipment($like, $operator)],
            ['ticket', 'Tickets', fn () => $this->tickets($like, $operator)],
        ];

        $groups = [];
        foreach ($sources as [$type, $label, $resolver]) {
            try {
                $items = $resolver();
                if ($items !== []) {
                    $groups[] = ['type' => $type, 'label' => $label, 'items' => $items];
                }
            } catch (\Throwable $e) {
                Log::warning('global_search.source_failed', ['type' => $type, 'error' => $e->getMessage()]);
            }
        }

        return $groups;
    }

    private function scoped(string $modelClass, ?string $operator)
    {
        $q = $modelClass::query();
        if ($operator && \Illuminate\Support\Facades\Schema::hasColumn((new $modelClass)->getTable(), 'operator_code')) {
            $q->where('operator_code', $operator);
        }

        return $q->limit(self::LIMIT);
    }

    private function customers(string $like, ?string $op): array
    {
        $match = $this->likeOperator();

        return $this->scoped(\Modules\Ilm\Models\Customer::class, $op)
            ->where(fn ($w) => $w->where('name', $match, $like)->orWhere('primary_msisdn', $match, $like)->orWhere('customer_id', $match, $like))
            ->get()->map(fn ($c) => ['id' => $c->customer_id, 'title' => $c->name, 'subtitle' => $c->primary_msisdn])->all();
    }

    private function accounts(string $like, ?string $op): array
    {
        $match = $this->likeOperator();

        return $this->scoped(\Modules\Ilm\Models\CustomerAccount::class, $op)
            ->where(fn ($w) => $w->where('account_number', $match, $like)->orWhere('account_id', $match, $like))
            ->get()->map(fn ($a) => ['id' => $a->customer_id, 'title' => $a->account_number, 'subtitle' => $a->status ?? null])->all();
    }

    private function subscriptions(string $like, ?string $op): array
    {
        $match = $this->likeOperator();

        return $this->scoped(\Modules\Subscription\Models\Subscription::class, $op)
            ->where(fn ($w) => $w->where('subscription_id', $match, $like)->orWhere('package_ref', $match, $like))
            ->get()->map(fn ($s) => ['id' => $s->subscription_id, 'title' => $s->subscription_id, 'subtitle' => $s->package_ref])->all();
    }

    private function invoices(string $like, ?string $op): array
    {
        $match = $this->likeOperator();

        return $this->scoped(\Modules\Billing\Invoicing\Models\Invoice::class, $op)
            ->where(fn ($w) => $w->where('legal_invoice_number', $match, $like)->orWhere('invoice_id', $match, $like))
            ->get()->map(fn ($i) => ['id' => $i->invoice_id, 'title' => $i->legal_invoice_number ?? $i->invoice_id, 'subtitle' => $i->status ?? null])->all();
    }

    private function payments(string $like, ?string $op): array
    {
        $match = $this->likeOperator();

        return $this->scoped(\Modules\Billing\Models\Payment::class, $op)
            ->where(fn ($w) => $w->where('payment_reference', $match, $like))
            ->get()->map(fn ($p) => ['id' => $p->getKey(), 'title' => $p->payment_reference, 'subtitle' => $p->customer_id])->all();
    }

    private function workOrders(string $like, ?string $op): array
    {
        $match = $this->likeOperator();

        return $this->scoped(\Modules\WorkOrder\Models\WorkOrder::class, $op)
            ->where(fn ($w) => $w->where('work_order_id', $match, $like)->orWhere('customer_id', $match, $like))
            ->get()->map(fn ($w) => ['id' => $w->work_order_id, 'title' => $w->work_order_id, 'subtitle' => $w->type.' · '.$w->status])->all();
    }

    private function equipment(string $like, ?string $op): array
    {
        $match = $this->likeOperator();

        return $this->scoped(\Modules\Osr\Models\EquipmentInstance::class, $op)
            ->where(fn ($w) => $w->where('serial', $match, $like))
            ->get()->map(fn ($e) => ['id' => $e->getKey(), 'title' => $e->serial, 'subtitle' => $e->status ?? null])->all();
    }

    private function tickets(string $like, ?string $op): array
    {
        $match = $this->likeOperator();

        return $this->scoped(\Modules\Ticketing\Models\Ticket::class, $op)
            ->where(fn ($w) => $w->where('ticket_number', $match, $like)->orWhere('subject', $match, $like)->orWhere('ticket_id', $match, $like))
            ->get()->map(fn ($t) => ['id' => $t->ticket_id, 'title' => $t->ticket_number ?? $t->ticket_id, 'subtitle' => $t->subject])->all();
    }

    private function likeOperator(): string
    {
        return config('database.default') === 'pgsql' ? 'ilike' : 'like';
    }
}
