<?php

namespace Modules\Ilm\Cvm\Services;
use Modules\Ilm\Services\AccountService;

use App\Foundation\Rules\RuleEngine;
use App\Foundation\Support\Context;
use Modules\Ilm\Models\CustomerAccount;

/**
 * EM-03 / CVM daily flag evaluator. Scans active accounts and asks the rules
 * engine (rules.cvm.flag-evaluation, operator-configurable) which retention/risk
 * flags apply from each account's facts (status, sub-status, dunning, tenure).
 * The decision's `flag` is raised via AccountService; `clearFlag` lifts it when
 * the rule no longer matches. Policy lives in Drools, not code.
 */
class CvmFlagEvaluatorService
{
    public function __construct(
        private readonly RuleEngine $rules,
        private readonly AccountService $accounts,
    ) {}

    /** @return array{scanned:int, flagged:int} */
    public function run(?string $operator = null): array
    {
        $operator ??= Context::operatorCode();
        $scanned = $flagged = 0;

        CustomerAccount::query()->where('operator_code', $operator)->chunkById(500, function ($accounts) use (&$scanned, &$flagged) {
            foreach ($accounts as $account) {
                $scanned++;
                $decision = $this->rules->evaluate('rules.cvm.flag-evaluation', [
                    'status' => $account->status,
                    'subStatus' => $account->sub_status,
                    'operatorCode' => $account->operator_code,
                ]);

                $flag = $decision['flag'] ?? null;
                if ($flag) {
                    $this->accounts->setFlag($account, $flag, ['ruleId' => $decision['ruleId'] ?? null], 'CVM_EVALUATOR');
                    $flagged++;
                } elseif (($decision['clearFlag'] ?? null)) {
                    $this->accounts->clearFlag($account, $decision['clearFlag'], 'CVM_EVALUATOR');
                }
            }
        });

        return ['scanned' => $scanned, 'flagged' => $flagged];
    }
}
