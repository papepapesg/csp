<?php

namespace Modules\Catalog\Rating\Services;

/** Applies executable pulse, allowance and charging policy to a resolved tariff card. */
class VoiceCallRater
{
    public function __construct(private readonly VoiceTariffResolver $resolver) {}

    /** @param array<string,mixed> $request */
    public function rate(array $request): array
    {
        $card = $this->resolver->resolve($request);
        if (($card['resolutionStatus'] ?? null) !== 'RESOLVED') {
            return $card;
        }

        $duration = max(0, (int) ($request['durationSeconds'] ?? 0));
        $remainingAllowance = max(0, (int) ($request['remainingAllowanceSeconds'] ?? 0));
        $policy = $card['chargePolicy'];
        if ($policy === 'QUARANTINE') {
            return ['resolutionStatus' => 'QUARANTINE', 'reason' => 'RATE_QUARANTINE'];
        }

        $billable = $this->roundToPulse(
            $duration,
            max(0, (int) $card['initialIncrementSeconds']),
            max(1, (int) $card['subsequentIncrementSeconds']),
        );
        if ($policy === 'BLOCKED') {
            return $this->result($card, 'BLOCKED', 0, 0, 0, 0.0);
        }
        if ($policy === 'ZERO_RATED') {
            return $this->result($card, 'ZERO_RATED', $billable, 0, 0, 0.0);
        }

        $allowanceUsed = min($billable, $remainingAllowance);
        $chargeable = $billable - $allowanceUsed;
        $units = str_contains(strtoupper((string) $card['unitType']), 'SECOND')
            ? $chargeable
            : $chargeable / 60.0;
        $amount = $units * (float) $card['unitPrice'];
        if ($chargeable > 0) {
            $amount = max(
                $amount + (float) $card['setupFeeAmount'],
                (float) $card['minimumChargeAmount'],
            );
        }

        return $this->result($card, 'CHARGED', $billable, $allowanceUsed, $chargeable, round($amount, 2));
    }

    private function roundToPulse(int $duration, int $initial, int $pulse): int
    {
        if ($duration <= 0) {
            return 0;
        }
        if ($duration <= $initial) {
            return $initial;
        }

        return $initial + (int) (ceil(($duration - $initial) / $pulse) * $pulse);
    }

    private function result(array $card, string $status, int $billable, int $allowanceUsed, int $chargeable, float $amount): array
    {
        return [
            'resolutionStatus' => 'RATED',
            'chargeStatus' => $status,
            'tariffPlanCode' => $card['tariffPlanCode'] ?? null,
            'zoneCode' => $card['zoneCode'] ?? null,
            'timeBandCode' => $card['timeBandCode'] ?? null,
            'billableSeconds' => $billable,
            'allowanceConsumedSeconds' => $allowanceUsed,
            'chargeableSeconds' => $chargeable,
            'amount' => $amount,
            'currency' => $card['currency'] ?? null,
            'taxableKind' => $card['taxableKind'] ?? null,
            'taxableRef' => $card['taxableRef'] ?? null,
        ];
    }
}
