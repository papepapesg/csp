<?php

namespace Modules\Billing\Services;

use Modules\Billing\Models\DunningProgram;

/**
 * BIL-04 program resolution (R-BIL-04-C-3). Picks the active (retired_at IS NULL) program
 * for an (operator_code, billing_mode), most-specific first: an exact operator match beats the
 * '*' wildcard. Returns the highest published version. Resolved once at dunning entry; the
 * version is then pinned on the state row for the episode's lifetime.
 */
class DunningProgramResolver
{
    public function resolve(string $operator, string $billingMode): ?DunningProgram
    {
        foreach ([$operator, '*'] as $scope) {
            $program = DunningProgram::query()
                ->where('operator_code', $scope)
                ->where('billing_mode', $billingMode)
                ->whereNull('retired_at')
                ->orderByDesc('version')
                ->first();
            if ($program) {
                return $program;
            }
        }

        return null;
    }
}
