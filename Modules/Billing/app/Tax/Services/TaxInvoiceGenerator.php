<?php

namespace Modules\Billing\Tax\Services;
use Modules\Billing\Services\CustomerSnapshotService;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Events\BillingEvents;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Tax\Models\TaxInvoice;
use Modules\Billing\Tax\Models\TaxOperatorConfig;
use Modules\Catalog\Services\TaxComputeService;

/**
 * BIL-02-TAX-01 generator (rule group G). Builds a tax invoice from a payment moment — the
 * customer's payment is tax-inclusive, so the generator decomposes the paid amount into base +
 * tax. POSTPAID scales the parent invoice's already-computed tax breakdown proportionally
 * (G-3, no recomputation); PREPAID topup/direct-pay decompose the inclusive total via
 * PLM-CFG-02 (G-4/G-5). Idempotent on the triggering event ref (T-5); writes a GENERATED tax
 * invoice with a gap-free TAX_INVOICE legal number (G-8) and emits TaxInvoiceIssued.
 */
class TaxInvoiceGenerator
{
    public function __construct(
        private readonly EventBus $events,
        private readonly CustomerSnapshotService $snapshots,
        private readonly TaxComputeService $taxCompute,
    ) {}

    /** Flow A — POSTPAID PaymentApplied: proportional to the parent invoice (G-3). */
    public function fromPaymentApplied(Invoice $parent, string $paymentId, float $paidAmount, string $eventRef): ?TaxInvoice
    {
        $operator = $parent->operator_code;
        if (! TaxOperatorConfig::enabled($operator)) {
            return null; // T-4
        }
        $total = (float) $parent->total_amount;
        $ratio = $total > 0 ? min(1.0, $paidAmount / $total) : 1.0;

        $parentTaxTotal = (float) ($parent->tax_summary['totalTaxAmount'] ?? ($parent->tax_total ?? 0));
        $subtotal = round(((float) $parent->subtotal_amount) * $ratio, 2);
        $taxTotal = round($parentTaxTotal * $ratio, 2);
        $lineItems = [[
            'description' => 'Payment against invoice '.($parent->legal_invoice_number ?? $parent->invoice_id),
            'service_category_code' => $parent->tax_summary['serviceCategory'] ?? null,
            'package_ref' => $parent->package_ref ?? null,
            'subtotal' => $subtotal, 'tax_total' => $taxTotal, 'total' => round($paidAmount, 2),
            'payment_ratio' => round($ratio, 4),
        ]];

        return $this->generate([
            'operator' => $operator, 'trigger_type' => TaxInvoice::TRIGGER_PAYMENT_APPLIED, 'trigger_ref' => $eventRef,
            'subscription_id' => $parent->subscription_id ?? null, 'customer_id' => $parent->customer_id ?? null,
            'account_id' => $parent->account_id ?? null, 'billing_mode' => 'POSTPAID', 'currency' => $parent->currency ?? 'KES',
            'original_invoice_id' => $parent->invoice_id, 'line_items' => $lineItems,
            'subtotal' => $subtotal, 'tax_total' => $taxTotal, 'total' => round($paidAmount, 2),
            'tax_summary' => ['totalTaxAmount' => $taxTotal, 'proportionalFrom' => $parent->invoice_id, 'ratio' => round($ratio, 4)],
            'metadata' => ['payment_id' => $paymentId],
        ]);
    }

    /** Flow B — PREPAID WalletToppedUp: decompose the full inclusive topup (G-4). */
    public function fromWalletTopup(array $e): ?TaxInvoice
    {
        $operator = $e['operatorCode'];
        if (! TaxOperatorConfig::enabled($operator)) {
            return null;
        }
        $total = (float) $e['topupAmount'];
        $serviceCategory = $e['serviceCategoryCode'] ?? 'INTERNET';
        [$subtotal, $taxTotal, $taxLines] = $this->decomposeInclusive($operator, $total, $serviceCategory);

        return $this->generate([
            'operator' => $operator, 'trigger_type' => TaxInvoice::TRIGGER_WALLET_TOPPED_UP, 'trigger_ref' => $e['eventId'],
            'subscription_id' => $e['subscriptionId'] ?? null, 'customer_id' => $e['customerId'] ?? null,
            'account_id' => $e['accountId'] ?? null, 'billing_mode' => 'PREPAID', 'currency' => $e['currency'] ?? 'KES',
            'original_invoice_id' => null,
            'line_items' => [[
                'description' => 'Wallet top-up — '.$serviceCategory,
                'service_category_code' => $serviceCategory, 'wallet_type_code' => $e['walletTypeCode'] ?? null,
                'package_ref' => 'TOPUP_'.($e['walletTypeCode'] ?? 'WALLET'),
                'subtotal' => $subtotal, 'tax_total' => $taxTotal, 'total' => round($total, 2),
            ]],
            'subtotal' => $subtotal, 'tax_total' => $taxTotal, 'total' => round($total, 2),
            'tax_summary' => ['totalTaxAmount' => $taxTotal, 'taxLines' => $taxLines, 'serviceCategory' => $serviceCategory],
            'metadata' => ['topup_event_id' => $e['eventId'], 'topup_payment_ref' => $e['paymentRef'] ?? null, 'topup_method' => $e['topupMethod'] ?? null],
        ]);
    }

    /** Flow C — PREPAID PaymentReceived (direct-pay, no wallet): decompose inclusive (G-5). */
    public function fromDirectPay(array $e): ?TaxInvoice
    {
        $operator = $e['operatorCode'];
        if (! TaxOperatorConfig::enabled($operator)) {
            return null;
        }
        $total = (float) $e['paidAmount'];
        $serviceCategory = $e['serviceCategoryCode'] ?? 'INTERNET';
        [$subtotal, $taxTotal, $taxLines] = $this->decomposeInclusive($operator, $total, $serviceCategory);

        return $this->generate([
            'operator' => $operator, 'trigger_type' => TaxInvoice::TRIGGER_PAYMENT_RECEIVED, 'trigger_ref' => $e['eventId'],
            'subscription_id' => $e['subscriptionId'] ?? null, 'customer_id' => $e['customerId'] ?? null,
            'account_id' => $e['accountId'] ?? null, 'billing_mode' => 'PREPAID', 'currency' => $e['currency'] ?? 'KES',
            'original_invoice_id' => $e['proFormaId'] ?? null,
            'line_items' => [[
                'description' => 'Cycle pre-payment — '.($e['packageRef'] ?? 'package'),
                'service_category_code' => $serviceCategory, 'package_ref' => $e['packageRef'] ?? null,
                'subtotal' => $subtotal, 'tax_total' => $taxTotal, 'total' => round($total, 2),
            ]],
            'subtotal' => $subtotal, 'tax_total' => $taxTotal, 'total' => round($total, 2),
            'tax_summary' => ['totalTaxAmount' => $taxTotal, 'taxLines' => $taxLines, 'serviceCategory' => $serviceCategory],
            'metadata' => ['payment_received_event_id' => $e['eventId'], 'payment_method' => $e['paymentMethod'] ?? null,
                'period_start' => $e['targetCycleStart'] ?? null, 'period_end' => $e['targetCycleEnd'] ?? null],
        ]);
    }

    /** @param array<string,mixed> $ctx */
    private function generate(array $ctx): TaxInvoice
    {
        $operator = $ctx['operator'];

        $existing = TaxInvoice::query()->where('operator_code', $operator)->where('triggering_event_ref', $ctx['trigger_ref'])->first();
        if ($existing) {
            return $existing; // T-5 idempotency
        }

        $snapshot = [];
        if (! empty($ctx['customer_id'])) {
            try {
                $snapshot = $this->snapshots->captureSnapshot($ctx['customer_id'], $ctx['account_id'] ?? null);
                $customer = \Modules\Ilm\Models\Customer::query()->find($ctx['customer_id']);
                $snapshot['tax_identifier'] = $customer?->tax_identifier ?? null;
            } catch (\Throwable) {
                // Snapshot best-effort for the stub; a real deployment queues + retries (GEN-01 F-6).
            }
        }

        return DB::transaction(function () use ($ctx, $operator, $snapshot) {
            try {
                $taxInvoice = TaxInvoice::query()->create([
                    'operator_code' => $operator,
                    'invoice_id' => $ctx['original_invoice_id'] ?? ('payevt-'.$ctx['trigger_ref']),
                    'subscription_id' => $ctx['subscription_id'] ?? null,
                    'customer_id' => $ctx['customer_id'] ?? null,
                    'billing_mode' => $ctx['billing_mode'],
                    'triggering_event_type' => $ctx['trigger_type'],
                    'triggering_event_ref' => $ctx['trigger_ref'],
                    'original_invoice_id' => $ctx['original_invoice_id'] ?? null,
                    'legal_invoice_number' => $this->nextLegalNumber($operator),
                    'currency' => $ctx['currency'],
                    'subtotal_amount' => $ctx['subtotal'], 'tax_total' => $ctx['tax_total'], 'total_amount' => $ctx['total'],
                    'tax_summary' => $ctx['tax_summary'], 'line_items' => $ctx['line_items'], 'customer_snapshot' => $snapshot,
                    'metadata' => $ctx['metadata'] ?? [],
                    'status' => TaxInvoice::GENERATED, 'payment_status' => 'PAID', 'issued_at' => now(),
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                return TaxInvoice::query()->where('operator_code', $operator)->where('triggering_event_ref', $ctx['trigger_ref'])->firstOrFail();
            }

            $this->events->publish(new DomainEvent(
                type: BillingEvents::TAX_INVOICE_ISSUED, topic: BillingEvents::TOPIC,
                payload: ['taxInvoiceId' => $taxInvoice->tax_invoice_id, 'legalNumber' => $taxInvoice->legal_invoice_number, 'total' => (string) $taxInvoice->total_amount, 'triggerType' => $taxInvoice->triggering_event_type],
                aggregateType: 'TaxInvoice', aggregateId: $taxInvoice->tax_invoice_id,
            ));

            return $taxInvoice;
        });
    }

    /**
     * Decompose a tax-inclusive total into base + tax via PLM-CFG-02's cascade. The compute
     * service is exclusive (base -> base+tax), so we derive the effective rate on a probe and
     * invert: base = total / (1 + rate). Returns [subtotal, taxTotal, taxLines].
     *
     * @return array{0:float,1:float,2:array}
     */
    private function decomposeInclusive(string $operator, float $total, string $serviceCategory): array
    {
        $probe = $this->taxCompute->compute([
            'operatorCode' => $operator, 'baseAmount' => 1000.0, 'taxableKind' => 'PACKAGE',
            'taxableRef' => $serviceCategory, 'currency' => 'KES',
        ]);
        $rate = ((float) ($probe['totalTaxAmount'] ?? 0)) / 1000.0;
        if ($rate <= 0) {
            return [round($total, 2), 0.0, []];
        }
        $subtotal = round($total / (1 + $rate), 2);
        $taxTotal = round($total - $subtotal, 2);
        $lines = array_map(fn ($l) => ['code' => $l['ruleCode'] ?? $l['code'] ?? 'TAX', 'amount' => round(((float) ($l['taxAmount'] ?? 0)) / 1000.0 * $subtotal, 2)], $probe['taxLines'] ?? []);

        return [$subtotal, $taxTotal, $lines];
    }

    /** Gap-free TAX_INVOICE legal number per operator + fiscal year (G-8). */
    private function nextLegalNumber(string $operator): string
    {
        $year = (int) now()->format('Y');
        DB::table('invoice_sequence')->insertOrIgnore(['operator_code' => $operator, 'type' => 'TAX_INVOICE', 'fiscal_year' => $year, 'last_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('invoice_sequence')->where('operator_code', $operator)->where('type', 'TAX_INVOICE')->where('fiscal_year', $year)->lockForUpdate()->first();
        $next = ((int) $row->last_value) + 1;
        DB::table('invoice_sequence')->where('operator_code', $operator)->where('type', 'TAX_INVOICE')->where('fiscal_year', $year)->update(['last_value' => $next, 'updated_at' => now()]);

        return sprintf('TAX-%s-%d-%06d', $operator, $year, $next);
    }
}
