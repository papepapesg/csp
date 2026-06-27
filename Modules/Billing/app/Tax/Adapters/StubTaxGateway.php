<?php

namespace Modules\Billing\Tax\Adapters;

use App\Foundation\Support\Id;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Tax\Contracts\TaxGateway;
use Modules\Billing\Models\Invoice;

/** Stub fiscalisation gateway: returns a fiscal number so flows run end-to-end. */
class StubTaxGateway implements TaxGateway
{
    public function fiscalize(Invoice $invoice): array
    {
        Log::info('[tax:stub] fiscalize', ['invoiceId' => $invoice->invoice_id, 'total' => $invoice->total_amount]);

        $fiscal = strtoupper(substr(Id::make('x'), 2, 12));

        return [
            'ok' => true,
            'fiscalNumber' => 'KRA-'.$fiscal,
            'controlCode' => substr(md5($invoice->invoice_id.$fiscal), 0, 16),
            'ref' => 'ETIMS-'.$fiscal,
            'response' => ['accepted' => true],
        ];
    }
}
