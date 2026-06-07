<?php

namespace Modules\Billing\Services;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Events\BillingEvents;
use Modules\Billing\Models\Invoice;

/**
 * BIL-02 invoicing core. Generates invoices from charge lines, computes totals
 * and issues a gap-free legal invoice number per operator/fiscal-year/type.
 */
class InvoiceService
{
    public function __construct(private readonly EventBus $events) {}

    /**
     * @param  array<string,mixed>  $header
     * @param  array<int,array<string,mixed>>  $lines
     */
    public function generate(array $header, array $lines): Invoice
    {
        return DB::transaction(function () use ($header, $lines) {
            $operator = $header['operator_code'] ?? Context::operatorCode();
            $type = $header['type'] ?? 'STANDARD';

            $subtotal = 0.0;
            $tax = 0.0;
            foreach ($lines as $line) {
                $lineSubtotal = (float) ($line['subtotal'] ?? ($line['quantity'] ?? 1) * ($line['unit_price'] ?? 0));
                $subtotal += $lineSubtotal;
                $tax += (float) ($line['tax_amount'] ?? 0);
            }
            $total = $subtotal + $tax;

            $invoice = Invoice::query()->create([
                'operator_code' => $operator,
                'account_id' => $header['account_id'],
                'customer_id' => $header['customer_id'] ?? null,
                'subscription_id' => $header['subscription_id'] ?? null,
                'type' => $type,
                'currency' => $header['currency'] ?? 'KES',
                'billing_mode' => $header['billing_mode'] ?? 'POSTPAID',
                'status' => Invoice::OPEN,
                'issue_date' => now(),
                'due_date' => now()->addDays((int) ($header['due_date_grace_days'] ?? 14)),
                'subtotal_amount' => $subtotal,
                'tax_amount_total' => $tax,
                'total_amount' => $total,
                'amount_paid' => 0,
                'amount_due' => $total,
                'legal_invoice_number' => $this->nextLegalNumber($operator, $type),
            ]);

            foreach ($lines as $line) {
                $invoice->lines()->create([
                    'description' => $line['description'],
                    'service_ref' => $line['service_ref'] ?? null,
                    'quantity' => $line['quantity'] ?? 1,
                    'unit_price' => $line['unit_price'] ?? 0,
                    'subtotal' => $line['subtotal'] ?? (($line['quantity'] ?? 1) * ($line['unit_price'] ?? 0)),
                    'tax_amount' => $line['tax_amount'] ?? 0,
                ]);
            }

            $this->events->publish(new DomainEvent(
                type: BillingEvents::INVOICE_GENERATED,
                topic: BillingEvents::TOPIC,
                payload: ['invoiceId' => $invoice->invoice_id, 'accountId' => $invoice->account_id, 'total' => (string) $total],
                aggregateType: 'Invoice',
                aggregateId: $invoice->invoice_id,
            ));

            return $invoice;
        });
    }

    /**
     * Gap-free legal number per (operator, fiscal year, type) using a locked
     * counter row (BIL-02). Must be called inside the generate() transaction.
     */
    private function nextLegalNumber(string $operator, string $type): string
    {
        $year = (int) now()->year;
        $prefix = $type === 'TAX' ? 'TInv' : 'Inv';

        // Ensure the counter row exists, then lock + increment it atomically.
        DB::table('invoice_sequence')->insertOrIgnore([
            'operator_code' => $operator,
            'type' => $type,
            'fiscal_year' => $year,
            'last_value' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('invoice_sequence')
            ->where('operator_code', $operator)
            ->where('type', $type)
            ->where('fiscal_year', $year)
            ->lockForUpdate()
            ->first();

        $next = ((int) $row->last_value) + 1;

        DB::table('invoice_sequence')
            ->where('operator_code', $operator)
            ->where('type', $type)
            ->where('fiscal_year', $year)
            ->update(['last_value' => $next, 'updated_at' => now()]);

        return sprintf('%s-%s-%d-%06d', $prefix, $operator, $year, $next);
    }
}
