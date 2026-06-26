<?php

namespace Modules\Billing\Http\Controllers;

use App\Foundation\Documents\DocumentRenderer;
use App\Foundation\Files\FileObject;
use App\Foundation\Files\FileStorageService;
use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\InvoiceService;
use Modules\Billing\Services\TaxService;

/**
 * BIL-02 invoicing API (generate + read; BIL-02-READ-01).
 */
class InvoiceController extends ApiController
{
    public function __construct(private readonly InvoiceService $invoices) {}

    /** GET /api/invoices?account_id=&status= */
    public function index(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = Invoice::query()
            ->where('operator_code', $request->query('operatorCode', Context::operatorCode()))
            ->when($request->query('account_id'), fn ($q, $a) => $q->where('account_id', $a))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->orderByDesc('issue_date')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    /** GET /api/invoices/{invoice} — header + SUMMARY lines with nested DETAIL (BIL-02-GEN-01). */
    public function show(Invoice $invoice): JsonResponse
    {
        $invoice->load('lines');
        $details = $invoice->lines->where('line_type', \Modules\Billing\Models\InvoiceLine::DETAIL)->groupBy('parent_summary_line_id');

        // Header fields stay top-level (back-compat); add the SUMMARY/DETAIL view.
        $payload = $invoice->toArray();
        $payload['grouping'] = ['dimension' => $invoice->grouping_dimension, 'key_values' => $invoice->grouping_key_values];
        $payload['customer_snapshot'] = $invoice->customer_snapshot; // frozen at generation (R-GEN-01-F-6)
        $payload['summary'] = $invoice->lines
            ->where('line_type', \Modules\Billing\Models\InvoiceLine::SUMMARY)
            ->sortBy('sort_order')->values()
            ->map(fn ($line) => [
                'id' => $line->id,
                'description' => $line->description,
                'package_ref' => $line->package_ref,
                'amount' => $line->subtotal,
                'details' => ($details[$line->id] ?? collect())->sortBy('sort_order')->map(fn ($d) => [
                    'service_category_code' => $d->service_category_code,
                    'description' => $d->description,
                    'quantity' => $d->quantity,
                    'amount' => $d->subtotal,
                    'tax_amount' => $d->tax_amount,
                ])->values(),
            ]);

        return ApiResponse::item($payload);
    }

    /** POST /api/invoices */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'string'],
            'customer_id' => ['nullable', 'string'],
            'subscription_id' => ['nullable', 'string'],
            'currency' => ['nullable', 'string', 'size:3'],
            'billing_mode' => ['nullable', 'in:POSTPAID,PREPAID'],
            'type' => ['nullable', 'in:STANDARD,TAX'],
            'due_date_grace_days' => ['nullable', 'integer', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.service_ref' => ['nullable', 'string'],
        ]);

        $invoice = $this->invoices->generate($data, $data['lines']);

        return ApiResponse::created($invoice->load('lines'));
    }

    /** POST /api/invoices/{invoice}/tax-invoice — fiscalise via the tax gateway. */
    public function issueTaxInvoice(Invoice $invoice, TaxService $tax): JsonResponse
    {
        return ApiResponse::created($tax->issue($invoice));
    }

    /**
     * GET /api/invoices/{invoice}/pdf — render (or fetch the cached) printable invoice PDF
     * and download it. This is the GENERATE path, separate from sending: it produces a
     * rendered_artifact and streams it back, creating NO notification/delivery record.
     * `?refresh=1` forces a re-render; `?locale=` picks the template locale.
     */
    public function pdf(Request $request, Invoice $invoice, DocumentRenderer $documents, FileStorageService $files): Response
    {
        $doc = $documents->render(
            operator: $invoice->operator_code,
            entityType: 'INVOICE',
            entityId: $invoice->invoice_id,
            format: 'PDF',
            purpose: 'INVOICE_DOCUMENT',
            locale: (string) $request->query('locale', 'en'),
            context: $this->renderContext($invoice),
            force: $request->boolean('refresh'),
        );

        $file = FileObject::query()->find($doc->fileId);
        abort_unless($file !== null, 404, 'Rendered invoice artifact not found.');

        return response((string) $files->contents($file), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$invoice->legal_invoice_number.'.pdf"',
        ]);
    }

    /**
     * Template placeholder data for the invoice document. Built here (Billing knows the
     * invoice shape); the document layer stays ignorant of it.
     *
     * @return array<string,mixed>
     */
    private function renderContext(Invoice $invoice): array
    {
        $invoice->loadMissing('lines');

        return [
            'invoice' => [
                'number' => $invoice->legal_invoice_number,
                'type' => $invoice->type,
                'status' => $invoice->status,
                'currency' => $invoice->currency,
                'issue_date' => (string) $invoice->issue_date,
                'due_date' => (string) $invoice->due_date,
                'subtotal' => (string) $invoice->subtotal_amount,
                'tax' => (string) $invoice->tax_amount_total,
                'total' => (string) $invoice->total_amount,
                'amount_due' => (string) $invoice->amount_due,
            ],
            'customer' => $invoice->customer_snapshot ?? ['id' => $invoice->customer_id],
            'operator' => $invoice->operator_code,
            'lines' => $invoice->lines->map(fn ($l) => [
                'description' => $l->description,
                'amount' => (string) $l->subtotal,
                'tax' => (string) $l->tax_amount,
            ])->values()->all(),
        ];
    }
}
