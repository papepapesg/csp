<?php

namespace Modules\Billing\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Events\BillingEvents;
use Modules\Billing\Models\TaxInvoice;
use Modules\Billing\Models\TaxInvoiceSigningFailure;
use Modules\Billing\Models\TaxOperatorConfig;
use Modules\Billing\Tax\TaxSignerRegistry;
use Modules\Billing\Tax\TaxSigningException;

/**
 * BIL-02-TAX-01 signing orchestration (rule groups S/F/C). Submits GENERATED tax invoices to
 * the operator's pluggable signer, records the outcome, and drives the retry/give-up policy:
 *   success            -> SIGNED + TaxInvoiceSigned
 *   transient failure  -> SIGNING_FAILED + backoff retry; 8 retries -> GAVE_UP_AUTO
 *   validation failure -> SIGNING_FAILED parked for admin (no auto-retry, F-3)
 * The signing flow is asynchronous from generation: a 1-minute scanner signs GENERATED rows, a
 * 5-minute scanner retries due transient failures.
 */
class TaxSigningService
{
    /** Transient retry backoff (R-TAX-01-F-2): 8 retries then give up. */
    private const BACKOFF = [300, 900, 1800, 3600, 7200, 14400, 28800, 86400];

    public function __construct(
        private readonly EventBus $events,
        private readonly TaxSignerRegistry $signers,
    ) {}

    /** Sign one tax invoice (S-2..S-4). Returns the refreshed row. */
    public function sign(TaxInvoice $taxInvoice): TaxInvoice
    {
        if (! in_array($taxInvoice->status, [TaxInvoice::GENERATED, TaxInvoice::SIGNING_FAILED, TaxInvoice::GAVE_UP_AUTO], true)) {
            return $taxInvoice;
        }
        $config = TaxOperatorConfig::forOperator($taxInvoice->operator_code) ?? new TaxOperatorConfig(['operator_code' => $taxInvoice->operator_code]);
        $signer = $this->signers->for($taxInvoice->operator_code);
        if (! $signer) {
            return $this->recordFailure($taxInvoice, new TaxSigningException('SIGNER_NOT_REGISTERED', 'No signer for operator', TaxInvoiceSigningFailure::VALIDATION));
        }

        $taxInvoice->update(['status' => TaxInvoice::PENDING_SIGNATURE]); // S-2 atomic-ish CAS

        try {
            $payload = $signer->sign($taxInvoice, $config);
        } catch (TaxSigningException $e) {
            return $this->recordFailure($taxInvoice, $e);
        }

        return DB::transaction(function () use ($taxInvoice, $payload) {
            $meta = $taxInvoice->metadata ?? [];
            $meta['tax_signature_data'] = $payload->raw + ['qr' => $payload->qrSignatureData];
            $taxInvoice->update([
                'status' => TaxInvoice::SIGNED,
                'signed_invoice_number' => $payload->signedInvoiceNumber,
                'signed_at' => now(),
                'signing_failure_type' => null, 'next_retry_at' => null,
                'metadata' => $meta,
            ]);
            $this->events->publish($this->event(BillingEvents::TAX_INVOICE_SIGNED, $taxInvoice, ['signedInvoiceNumber' => $payload->signedInvoiceNumber]));

            return $taxInvoice->refresh();
        });
    }

    private function recordFailure(TaxInvoice $taxInvoice, TaxSigningException $e): TaxInvoice
    {
        return DB::transaction(function () use ($taxInvoice, $e) {
            $current = (int) $taxInvoice->retry_count;
            $attempt = $current + 1;
            $transient = $e->isTransient();
            // Validation failures don't increment the retry budget (F-3); transient ones do.
            $newCount = $transient ? $attempt : $current;
            $giveUp = $transient && $attempt > count(self::BACKOFF);
            $next = $transient && ! $giveUp ? now()->addSeconds(self::BACKOFF[$attempt - 1] ?? end(self::BACKOFF)) : null;

            TaxInvoiceSigningFailure::query()->create([
                'tax_invoice_id' => $taxInvoice->tax_invoice_id, 'operator_code' => $taxInvoice->operator_code,
                'attempt_number' => $attempt, 'failed_at' => now(), 'failure_type' => $e->failureType, 'failure_category' => $e->category,
                'gateway_response_code' => $e->gatewayResponseCode, 'gateway_response_body' => $e->gatewayResponseBody,
                'error_message' => $e->getMessage(), 'retry_scheduled_at' => $next,
            ]);

            $taxInvoice->update([
                'status' => $giveUp ? TaxInvoice::GAVE_UP_AUTO : TaxInvoice::SIGNING_FAILED,
                'signing_failure_type' => $e->failureType, 'retry_count' => $newCount, 'next_retry_at' => $next,
            ]);

            $this->events->publish($this->event(BillingEvents::TAX_INVOICE_SIGNING_FAILED, $taxInvoice, ['failureType' => $e->failureType, 'category' => $e->category, 'attempt' => $attempt]));
            if ($giveUp) {
                $this->events->publish($this->event(BillingEvents::TAX_INVOICE_GAVE_UP, $taxInvoice, ['attempts' => $attempt]));
            }

            return $taxInvoice->refresh();
        });
    }

    /** S-2 signing scanner: sign GENERATED tax invoices (1-minute cadence). */
    public function signScan(?string $operator = null, int $limit = 500): int
    {
        $rows = TaxInvoice::query()->where('status', TaxInvoice::GENERATED)
            ->when($operator, fn ($q) => $q->where('operator_code', $operator))->limit($limit)->get();
        foreach ($rows as $row) {
            $this->sign($row);
        }

        return $rows->count();
    }

    /** F-2 retry scanner: re-submit due transient SIGNING_FAILED rows (5-minute cadence). */
    public function retryScan(?string $operator = null, int $limit = 500): int
    {
        // Transient failures are exactly those with a scheduled next_retry_at; validation
        // failures have next_retry_at NULL and are skipped (F-3).
        $rows = TaxInvoice::query()->where('status', TaxInvoice::SIGNING_FAILED)
            ->whereNotNull('next_retry_at')->where('next_retry_at', '<=', now())
            ->when($operator, fn ($q) => $q->where('operator_code', $operator))->limit($limit)->get();

        foreach ($rows as $row) {
            $this->sign($row);
        }

        return $rows->count();
    }

    /** C-3 admin re-sign of a SIGNING_FAILED / GAVE_UP_AUTO invoice (resets the retry budget). */
    public function retrySigning(TaxInvoice $taxInvoice): TaxInvoice
    {
        $taxInvoice->update(['retry_count' => 0, 'next_retry_at' => null]);

        return $this->sign($taxInvoice->refresh());
    }

    /**
     * Cancel a tax invoice (rule group C). Unsigned -> CANCELLED, no gateway call (C-2). Signed
     * -> requires the gateway cancellation + an authority reference, behind dual approval (C-1,
     * enforced by the controller's TAX_COMPLIANCE_OFFICER + approve step).
     */
    public function cancel(TaxInvoice $taxInvoice, string $reasonCode, ?string $actor = null): TaxInvoice
    {
        if (in_array($taxInvoice->status, TaxInvoice::UNSIGNED, true)) {
            $taxInvoice->update(['status' => TaxInvoice::CANCELLED, 'cancel_reason_code' => $reasonCode]);
            $this->events->publish($this->event(BillingEvents::TAX_INVOICE_CANCELLED, $taxInvoice, ['reasonCode' => $reasonCode, 'signed' => false]));

            return $taxInvoice->refresh();
        }
        if ($taxInvoice->status !== TaxInvoice::SIGNED) {
            throw DomainException::conflict('Tax invoice cannot be cancelled in its current state.');
        }

        $config = TaxOperatorConfig::forOperator($taxInvoice->operator_code) ?? new TaxOperatorConfig(['operator_code' => $taxInvoice->operator_code]);
        $signer = $this->signers->for($taxInvoice->operator_code);
        $ref = $signer?->cancel($taxInvoice, $reasonCode, $config);
        $taxInvoice->update(['status' => TaxInvoice::CANCELLED, 'cancel_reason_code' => $reasonCode, 'cancellation_reference' => $ref]);
        $this->events->publish($this->event(BillingEvents::TAX_INVOICE_CANCELLED, $taxInvoice, ['reasonCode' => $reasonCode, 'signed' => true, 'cancellationReference' => $ref]));

        return $taxInvoice->refresh();
    }

    /** D-4 offline resolution: annotate a failed/gave-up invoice; no further auto-retries. */
    public function resolveNoAction(TaxInvoice $taxInvoice, string $notes, ?string $actor = null): TaxInvoice
    {
        $taxInvoice->update(['resolution_notes' => $notes, 'next_retry_at' => null,
            'metadata' => ($taxInvoice->metadata ?? []) + ['resolved_no_action_by' => $actor, 'resolved_at' => now()->toIso8601String()]]);

        return $taxInvoice->refresh();
    }

    /** @param array<string,mixed> $extra */
    private function event(string $type, TaxInvoice $taxInvoice, array $extra): DomainEvent
    {
        return new DomainEvent(
            type: $type, topic: BillingEvents::TOPIC,
            payload: ['taxInvoiceId' => $taxInvoice->tax_invoice_id, 'operatorCode' => $taxInvoice->operator_code, 'legalNumber' => $taxInvoice->legal_invoice_number] + $extra,
            aggregateType: 'TaxInvoice', aggregateId: $taxInvoice->tax_invoice_id,
        );
    }
}
