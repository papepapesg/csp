<?php

namespace Modules\Rules\Console;

use App\Foundation\Rules\RuleEngine;
use Illuminate\Console\Command;

/**
 * Ops review/debug: evaluate a rule set against sample facts and show the result
 * (read-only — the engine contract is side-effect free: facts in, result facts
 * out, NO persistence). Mirrors the studio test endpoint
 * (DecisionTableController::evaluate) so ops can debug routing/eligibility
 * without touching live data. Operator scope comes from the request Context.
 */
class RuleEvaluateCommand extends Command
{
    protected $signature = 'sophix:rules:evaluate {ruleSet : The rule_set key} {--facts= : JSON object of sample facts, e.g. \'{"amount":100}\'}';

    protected $description = 'Review: dry-run a rule set against sample facts and show the decision (read-only, no persistence)';

    public function handle(RuleEngine $rules): int
    {
        $ruleSet = $this->argument('ruleSet');

        $raw = (string) ($this->option('facts') ?? '{}');
        $facts = json_decode($raw, true);
        if (! is_array($facts)) {
            $this->error('--facts must be a JSON object, e.g. --facts=\'{"amount":100,"operatorCode":"OP1"}\'.');

            return self::FAILURE;
        }

        $result = $rules->assess($ruleSet, $facts);

        $this->info("Assessment of [{$ruleSet}]:");
        $this->table(['Field', 'Value'], [
            ['decision', json_encode($result['decision'], JSON_UNESCAPED_SLASHES)],
            ['firedRules', implode(', ', (array) $result['firedRules']) ?: '— (none fired; default output)'],
            ['validationErrors', (string) count($result['validationErrors'])],
        ]);

        foreach ($result['validationErrors'] as $err) {
            $this->warn("  {$err['ruleId']}: [{$err['field']}] {$err['message']}".($err['decisionCode'] ? " ({$err['decisionCode']})" : ''));
        }

        return self::SUCCESS;
    }
}
