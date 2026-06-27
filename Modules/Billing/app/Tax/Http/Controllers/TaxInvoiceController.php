<?php

namespace Modules\Billing\Tax\Http\Controllers;

use App\Foundation\Errors\DomainException;
use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Payments\Models\PaymentLedger;
use Modules\Billing\Tax\Models\TaxInvoice;
use Modules\Billing\Tax\Services\TaxInvoiceGenerator;
use Modules\Billing\Tax\Services\TaxSigningService;

/**
 * BIL-02-TAX-01 admin operations (rule group D) + cancellation/re-signing (group C).
 */
class TaxInvoiceController extends ApiController
{
    public function __construct(
        private readonly TaxSigningService $signing,
        private readonly TaxInvoiceGenerator $generator,
    ) {}

    /** GET /api/tax-invoices/dashboard — operations counts per state/failure type (D-1). */
    public function dashboard(Request $request): JsonResponse
    {
        $operator = $request->query('operator', Context::operatorCode());
        $base = TaxInvoice::query()->where('operator_code', $operator);

        return ApiResponse::item([
            'pendingGeneration' => (clone $base)->where('status', TaxInvoice::GENERATED)->count(),
            'pendingSignature' => (clone $base)->where('status', TaxInvoice::PENDING_SIGNATURE)->count(),
            'signed' => (clone $base)->where('status', TaxInvoice::SIGNED)->count(),
            'signingFailedByType' => (clone $base)->where('status', TaxInvoice::SIGNING_FAILED)
                ->selectRaw('signing_failure_type, count(*) as c')->groupBy('signing_failure_type')->pluck('c', 'signing_failure_type'),
            'gaveUp' => (clone $base)->where('status', TaxInvoice::GAVE_UP_AUTO)->count(),
            'cancelled' => (clone $base)->where('status', TaxInvoice::CANCELLED)->count(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = TaxInvoice::query()
            ->where('operator_code', $request->query('operator', Context::operatorCode()))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    /** GET /api/tax-invoices/{taxInvoice} — full detail incl. lifecycle (D-2). */
    public function show(TaxInvoice $taxInvoice): JsonResponse
    {
        return ApiResponse::item(['taxInvoice' => $taxInvoice, 'failures' => $taxInvoice->failures()->orderBy('attempt_number')->get()]);
    }

    /** GET /api/tax-invoices/{taxInvoice}/signing-history — all signing attempts. */
    public function signingHistory(TaxInvoice $taxInvoice): JsonResponse
    {
        return ApiResponse::item(['items' => $taxInvoice->failures()->orderBy('attempt_number')->get(),
            'signedInvoiceNumber' => $taxInvoice->signed_invoice_number, 'status' => $taxInvoice->status]);
    }

    /** GET /api/tax-invoices/{taxInvoice}/pdf — placeholder; READ-01/NOT-01 own real rendering. */
    public function pdf(TaxInvoice $taxInvoice): JsonResponse
    {
        return ApiResponse::item([
            'taxInvoiceId' => $taxInvoice->tax_invoice_id, 'legalNumber' => $taxInvoice->legal_invoice_number,
            'signedInvoiceNumber' => $taxInvoice->signed_invoice_number, 'qr' => $taxInvoice->metadata['tax_signature_data']['qr'] ?? null,
            'lineItems' => $taxInvoice->line_items, 'taxSummary' => $taxInvoice->tax_summary,
        ]);
    }

    /** POST /api/tax-invoices/manual — generate for a payment that should have produced one (D-3). */
    public function manual(Request $request): JsonResponse
    {
        $data = $request->validate(['payment_id' => ['required_without:invoice_id', 'string'], 'invoice_id' => ['required_without:payment_id', 'string']]);
        $invoice = null;
        if (! empty($data['invoice_id'])) {
            $invoice = Invoice::query()->findOrFail($data['invoice_id']);
        } elseif (! empty($data['payment_id'])) {
            $payment = PaymentLedger::query()->where('payment_id', $data['payment_id'])->firstOrFail();
            $invoice = $payment->target_invoice_id ? Invoice::query()->find($payment->target_invoice_id) : null;
        }
        if (! $invoice) {
            throw DomainException::notFound('No invoice resolved for manual tax-invoice generation.');
        }
        $tax = $this->generator->fromPaymentApplied($invoice, $data['payment_id'] ?? 'manual', (float) $invoice->total_amount, 'tax-manual-'.($data['payment_id'] ?? $invoice->invoice_id));

        return $tax ? ApiResponse::created($tax) : ApiResponse::item(['generated' => false, 'reason' => 'TAX_DISABLED']);
    }

    /** POST /api/tax-invoices/{taxInvoice}/retry-signing — admin re-sign (C-3). */
    public function retrySigning(TaxInvoice $taxInvoice): JsonResponse
    {
        return ApiResponse::item($this->signing->retrySigning($taxInvoice));
    }

    /** POST /api/tax-invoices/{taxInvoice}/resolve-no-action — offline reconciliation note (D-4). */
    public function resolveNoAction(Request $request, TaxInvoice $taxInvoice): JsonResponse
    {
        $notes = $request->validate(['notes' => ['required', 'string']])['notes'];

        return ApiResponse::item($this->signing->resolveNoAction($taxInvoice, $notes, $request->user()?->uid));
    }

    /**
     * POST /api/tax-invoices/{taxInvoice}/cancel — request cancellation. Unsigned cancels
     * immediately (C-2); a SIGNED invoice records the request and waits for compliance approval
     * (C-1 dual-approval), enforced by the approve endpoint's role gate.
     */
    public function cancel(Request $request, TaxInvoice $taxInvoice): JsonResponse
    {
        $data = $request->validate(['reason_code' => ['required', 'string']]);
        if (in_array($taxInvoice->status, TaxInvoice::UNSIGNED, true)) {
            return ApiResponse::item($this->signing->cancel($taxInvoice, $data['reason_code'], $request->user()?->uid));
        }
        // Signed: stage the request; a compliance officer must approve.
        $taxInvoice->update(['cancel_reason_code' => $data['reason_code'], 'cancel_requested_by' => $request->user()?->uid]);

        return ApiResponse::item(['taxInvoiceId' => $taxInvoice->tax_invoice_id, 'status' => $taxInvoice->status, 'cancellationPending' => true]);
    }

    /** POST /api/tax-invoices/{taxInvoice}/cancel/approve — compliance officer approves (C-1). */
    public function approveCancel(Request $request, TaxInvoice $taxInvoice): JsonResponse
    {
        if ($taxInvoice->status !== TaxInvoice::SIGNED || ! $taxInvoice->cancel_reason_code) {
            throw DomainException::conflict('No pending signed-cancellation to approve.');
        }
        // Segregation: the approver must differ from the requester (dual approval).
        if ($taxInvoice->cancel_requested_by && $taxInvoice->cancel_requested_by === $request->user()?->uid) {
            throw DomainException::ruleRejected('SELF_APPROVAL_NOT_ALLOWED', 'The cancellation approver must differ from the requester.');
        }

        return ApiResponse::item($this->signing->cancel($taxInvoice, $taxInvoice->cancel_reason_code, $request->user()?->uid));
    }
}
