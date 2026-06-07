<?php

namespace Modules\Billing\Contracts;

use Modules\Billing\Models\Invoice;

/**
 * Tax-authority fiscalisation gateway (BIL-02-TAX-01). Default driver is a stub
 * that returns a fiscal number so the flow works end-to-end; a real driver
 * (e.g. KRA eTIMS) implements the same contract. Selected via SOPHIX_TAX_DRIVER.
 */
interface TaxGateway
{
    /** @return array{ok:bool, fiscalNumber?:string, controlCode?:string, ref?:string, response?:array, error?:string} */
    public function fiscalize(Invoice $invoice): array;
}
