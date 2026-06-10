<?php

namespace Modules\Billing\Services;

/**
 * BIL-01 charge — a pre-tax line item the Charging Engine returns for a
 * subscription + intent + cycle window (BIL-02-GEN-01 glossary). The DD's charge
 * schema: wallet_type_code, package_ref, service_category_code, amount,
 * description_key, quantity. The recurring-vs-usage distinction is carried by
 * service_category_code (SUBSCRIPTION vs VOICE/DATA/SMS) — NOT a separate type
 * field — and the invoice TYPE is carried by the generator (CYCLE_POSTPAID,
 * ONE_OFF_INVOICE, …), not the charge.
 */
final class Charge
{
    public function __construct(
        public readonly string $serviceCategoryCode,   // SUBSCRIPTION | VOICE | DATA | SMS | …
        public readonly string $descriptionKey,        // translation key; resolved by the line builder (R-GEN-01-L-5)
        public readonly float $amount,                 // pre-tax
        public readonly float $quantity = 1.0,
        public readonly ?string $packageRef = null,
        public readonly ?string $walletTypeCode = null,
    ) {}

    /** The value of the grouping dimension this charge falls under (R-GEN-01-C-3). */
    public function groupKey(string $dimension): string
    {
        return match ($dimension) {
            'WALLET' => $this->walletTypeCode ?? 'DEFAULT',
            'PACKAGE' => $this->packageRef ?? 'GENERAL',
            'SERVICE_CATEGORY' => $this->serviceCategoryCode,
            default => 'ALL', // SINGLE
        };
    }
}
