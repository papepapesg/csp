<?php

namespace Modules\Billing\Services;

/**
 * BIL-01 charge — one pre-tax chargeable line the Charging Engine produces for a
 * subscription/cycle. The charge_type is the heart of the cycle-vs-usage
 * distinction: RECURRING is the fixed periodic package fee; USAGE is metered
 * consumption (voice/data/SMS rated from CDRs); ONE_OFF is an ad-hoc fee. Each
 * carries the classification the grouping policy and tax engine need.
 */
final class Charge
{
    public const RECURRING = 'RECURRING';

    public const USAGE = 'USAGE';

    public const ONE_OFF = 'ONE_OFF';

    public function __construct(
        public readonly string $chargeType,
        public readonly string $serviceCategoryCode,   // SUBSCRIPTION | VOICE | DATA | SMS | TV | …
        public readonly string $description,
        public readonly float $amount,                 // pre-tax
        public readonly float $quantity = 1.0,
        public readonly ?string $packageRef = null,
        public readonly ?string $walletTypeCode = null,
    ) {}

    /** The value of the grouping dimension this charge falls under. */
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
