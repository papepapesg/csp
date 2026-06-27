<?php

namespace Modules\Billing\Invoicing\Services;
use Modules\Billing\Invoicing\Services\Charge;
use Modules\Billing\Invoicing\Services\CustomerSnapshotService;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\I18n\TranslationService;
use App\Foundation\Models\OperatorConfig;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Events\BillingEvents;
use Modules\Billing\Invoicing\Models\Invoice;
use Modules\Catalog\Tax\Services\TaxComputeService;

/**
 * BIL-02 invoicing core. Generates invoices from charge lines, computes totals
 * and issues a gap-free legal invoice number per operator/fiscal-year/type.
 * The structured path (BIL-02-GEN-01) builds a SUMMARY/DETAIL line hierarchy
 * from typed BIL-01 charges, resolves description keys via the operator
 * translation catalog (R-GEN-01-L-5), computes per-line tax through PLM-CFG-02
 * (R-GEN-01-L-2), and applies the operator's grouping policy.
 */
class InvoiceService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly TranslationService $translations,
        private readonly TaxComputeService $tax,
        private readonly CustomerSnapshotService $snapshots,
    ) {}

    /**
     * BIL-02-GEN-01: build invoice(s) from typed BIL-01 charges, applying the
     * operator's grouping policy. The dimension may split the charges into more
     * than one invoice (e.g. one per wallet); within each invoice the lines form
     * a SUMMARY (per package) → DETAIL (per charge) hierarchy.
     *
     * @param  array<string,mixed>  $header
     * @param  array<int,Charge>  $charges
     * @return Collection<int,Invoice>
     */
    public function generateFromCharges(array $header, array $charges, string $triggerCode = 'CYCLE_POSTPAID'): Collection
    {
        $operator = $header['operator_code'] ?? Context::operatorCode();
        $dimension = (string) (DB::table('invoice_grouping_config')
            ->where('operator_code', $operator)->where('trigger_code', $triggerCode)
            ->value('grouping_dimension') ?? 'SINGLE');

        // R-GEN-01-F-6: capture the customer snapshot ONCE, before charges, and
        // reuse it across groups (same subscription, same moment). A fetch failure
        // throws — no invoice is written with a partial snapshot.
        $snapshot = ! empty($header['customer_id'])
            ? $this->snapshots->captureSnapshot($header['customer_id'], $header['account_id'] ?? null)
            : null;

        // One invoice per group in the policy (R-GEN-01-C-4).
        $groups = collect($charges)->groupBy(fn (Charge $c) => $c->groupKey($dimension));

        return $groups->map(fn (Collection $groupCharges, string $groupKey) => DB::transaction(
            fn () => $this->writeStructuredInvoice($header, $operator, $dimension, $groupKey, $groupCharges->all(), $snapshot)
        ))->values();
    }

    /**
     * Persist one grouped invoice with a SUMMARY/DETAIL line hierarchy.
     *
     * @param  array<int,Charge>  $charges
     */
    private function writeStructuredInvoice(array $header, string $operator, string $dimension, string $groupKey, array $charges, ?array $snapshot = null): Invoice
    {
        $type = $header['type'] ?? 'STANDARD';
        $currency = $header['currency'] ?? 'KES';
        // R-GEN-01-L-5: resolve description keys in the CUSTOMER's language
        // (from the snapshot), falling back to the operator's configured locale.
        $locale = $snapshot['preferredLanguage'] ?? OperatorConfig::forOperator($operator)?->default_locale ?? 'en';
        $resources = $this->translations->resources($locale, $operator);
        $customerCategory = $snapshot['customerCategory'] ?? 'RESIDENTIAL';
        $customerLocation = $snapshot['billingAddress'] ?? null;

        $invoice = Invoice::query()->create([
            'operator_code' => $operator,
            'account_id' => $header['account_id'],
            'customer_id' => $header['customer_id'] ?? null,
            'customer_snapshot' => $snapshot,
            'subscription_id' => $header['subscription_id'] ?? null,
            'type' => $type,
            'grouping_dimension' => $dimension,
            'grouping_key_values' => $groupKey,
            'currency' => $currency,
            'billing_mode' => $header['billing_mode'] ?? 'POSTPAID',
            'status' => Invoice::OPEN,
            'issue_date' => now(),
            'due_date' => now()->addDays((int) ($header['due_date_grace_days'] ?? 14)),
            'subtotal_amount' => 0, 'tax_amount_total' => 0, 'total_amount' => 0,
            'amount_paid' => 0, 'amount_due' => 0,
            'legal_invoice_number' => $this->nextLegalNumber($operator, $type),
        ]);

        $subtotal = 0.0;
        $taxTotal = 0.0;
        $taxSummary = [];            // aggregated per tax component across all lines
        $summaryOrder = 0;

        // SUMMARY per package; DETAIL per charge under it (R-GEN-01-L-1).
        foreach (collect($charges)->groupBy(fn (Charge $c) => $c->packageRef ?? 'GENERAL') as $pkg => $pkgCharges) {
            $summaryOrder += 10;
            $summaryId = Id::make('invl');
            $summaryAmount = round($pkgCharges->sum(fn (Charge $c) => $c->amount), 2);
            $invoice->lines()->create([
                'id' => $summaryId,
                'line_type' => 'SUMMARY',
                'description' => $pkg === 'GENERAL' ? ($resources['billing.line.charges'] ?? 'Charges') : ($resources['billing.line.package'] ?? 'Package').' — '.$pkg,
                'package_ref' => $pkg === 'GENERAL' ? null : $pkg,
                'quantity' => 1, 'unit_price' => $summaryAmount, 'subtotal' => $summaryAmount, 'tax_amount' => 0,
                'sort_order' => $summaryOrder,
            ]);

            $detailOrder = $summaryOrder;
            foreach ($pkgCharges as $charge) {
                $detailOrder++;
                // R-GEN-01-L-2: per-line tax via PLM-CFG-02. No tax config for the
                // operator resolves to zero tax (R-PLM-02-AP-2/3), not a failure.
                try {
                    $taxResult = $this->tax->compute([
                        'operatorCode' => $operator, 'baseAmount' => $charge->amount, 'currency' => $currency,
                        'taxableKind' => $charge->serviceCategoryCode, 'taxableRef' => $charge->packageRef,
                        'customerCategory' => $customerCategory, 'customerLocation' => $customerLocation,
                    ]);
                } catch (\App\Foundation\Errors\DomainException) {
                    $taxResult = ['totalTaxAmount' => 0, 'taxLines' => []];
                }
                $lineTax = round((float) ($taxResult['totalTaxAmount'] ?? 0), 2);
                $taxTotal += $lineTax;
                $subtotal += $charge->amount;
                foreach (($taxResult['taxLines'] ?? []) as $tl) {
                    $code = $tl['ruleCode'] ?? $tl['code'] ?? 'TAX';
                    $taxSummary[$code] = round(($taxSummary[$code] ?? 0) + (float) ($tl['amount'] ?? 0), 2);
                }

                $invoice->lines()->create([
                    'line_type' => 'DETAIL',
                    'parent_summary_line_id' => $summaryId,
                    'service_category_code' => $charge->serviceCategoryCode,
                    'package_ref' => $charge->packageRef,
                    'wallet_type_code' => $charge->walletTypeCode,
                    'description' => $resources[$charge->descriptionKey] ?? $charge->descriptionKey,
                    'quantity' => $charge->quantity,
                    'unit_price' => $charge->quantity > 0 ? round($charge->amount / $charge->quantity, 4) : $charge->amount,
                    'subtotal' => $charge->amount,
                    'tax_amount' => $lineTax,
                    'tax_breakdown' => $taxResult['taxLines'] ?? [],
                    'sort_order' => $detailOrder,
                ]);
            }
        }

        $subtotal = round($subtotal, 2);
        $taxTotal = round($taxTotal, 2);
        $invoice->update([
            'subtotal_amount' => $subtotal,
            'tax_amount_total' => $taxTotal,
            'tax_summary' => $taxSummary,
            'total_amount' => $subtotal + $taxTotal,
            'amount_due' => $subtotal + $taxTotal,
        ]);

        $this->events->publish(new DomainEvent(
            type: BillingEvents::INVOICE_GENERATED,
            topic: BillingEvents::TOPIC,
            payload: ['invoiceId' => $invoice->invoice_id, 'accountId' => $invoice->account_id, 'total' => (string) ($subtotal + $taxTotal), 'groupingDimension' => $dimension, 'groupingKeyValues' => $groupKey],
            aggregateType: 'Invoice',
            aggregateId: $invoice->invoice_id,
        ));

        return $invoice->refresh();
    }

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
