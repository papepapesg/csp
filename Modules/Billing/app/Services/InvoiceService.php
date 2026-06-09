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
     * BIL-02-GEN-01: issue a CREDIT_NOTE / DEBIT_NOTE document. A note is an
     * invoice row of its own (own gap-free legal number, original_invoice_id
     * back-reference) but it is never a receivable — status ISSUED, no due date.
     * Emits CreditNoteIssued / DebitNoteIssued for BIL-01-CN-01 to apply.
     *
     * @param  array<string,mixed>  $header
     */
    public function issueNote(string $noteType, array $header): Invoice
    {
        return DB::transaction(function () use ($noteType, $header) {
            $operator = $header['operator_code'] ?? Context::operatorCode();
            $amount = round((float) $header['amount'], 2);

            $note = Invoice::query()->create([
                'operator_code' => $operator,
                'account_id' => $header['account_id'] ?? null,
                'customer_id' => $header['customer_id'] ?? null,
                'subscription_id' => $header['subscription_id'] ?? null,
                'type' => $noteType,
                'original_invoice_id' => $header['original_invoice_id'] ?? null,
                'currency' => $header['currency'] ?? 'KES',
                'billing_mode' => $header['billing_mode'] ?? 'POSTPAID',
                'status' => Invoice::ISSUED,
                'issue_date' => now(),
                'due_date' => null,
                'subtotal_amount' => $amount,
                'tax_amount_total' => 0,
                'total_amount' => $amount,
                'amount_paid' => 0,
                'amount_due' => 0,
                'legal_invoice_number' => $this->nextLegalNumber($operator, $noteType),
            ]);

            $note->lines()->create([
                'description' => $header['description'] ?? $noteType,
                'quantity' => 1,
                'unit_price' => $amount,
                'subtotal' => $amount,
                'tax_amount' => 0,
            ]);

            $this->events->publish(new DomainEvent(
                type: $noteType === Invoice::CREDIT_NOTE ? BillingEvents::CREDIT_NOTE_ISSUED : BillingEvents::DEBIT_NOTE_ISSUED,
                topic: BillingEvents::TOPIC,
                payload: [
                    'noteId' => $note->invoice_id,
                    'noteType' => $noteType === Invoice::CREDIT_NOTE ? 'CREDIT' : 'DEBIT',
                    'customerId' => $note->customer_id,
                    'targetInvoiceId' => $note->original_invoice_id,
                    'targetWalletRef' => $header['target_wallet_ref'] ?? null,
                    'adjustmentRequestId' => $header['adjustment_request_id'] ?? null,
                    'amount' => (string) $amount,
                    'currency' => $note->currency,
                    'reasonCode' => $header['reason_code'] ?? null,
                ],
                aggregateType: 'Invoice',
                aggregateId: $note->invoice_id,
            ));

            return $note;
        });
    }

    /**
     * Gap-free legal number per (operator, fiscal year, type) using a locked
     * counter row (BIL-02). Must be called inside the generate() transaction.
     */
    private function nextLegalNumber(string $operator, string $type): string
    {
        $year = (int) now()->year;
        $prefix = match ($type) {
            'TAX' => 'TInv',
            Invoice::CREDIT_NOTE => 'CN',
            Invoice::DEBIT_NOTE => 'DN',
            default => 'Inv',
        };

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
