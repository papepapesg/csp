<?php

namespace Modules\Rules\Console;

use Illuminate\Console\Command;
use Modules\Rules\Models\DecisionTable;

/**
 * Ops review: show the decision table that is LIVE for a rule set (read-only) —
 * its inputs, every rule's conditions and outputs, the default output and the
 * deployed version. Resolution matches the engine: DEPLOYED only, operator-
 * specific overrides the global default, highest version wins.
 */
class RuleTableShowCommand extends Command
{
    protected $signature = 'sophix:rules:table-show {ruleSet : The rule_set key} {--operator= : Resolve for this operator code (default: global)}';

    protected $description = 'Review: show a rule set\'s deployed decision table (inputs, rules, default output, version) (read-only)';

    public function handle(): int
    {
        $ruleSet = $this->argument('ruleSet');
        $operator = $this->option('operator');

        $table = DecisionTable::query()
            ->where('rule_set', $ruleSet)
            ->where('status', DecisionTable::DEPLOYED)
            ->where(fn ($q) => $q->where('operator_code', $operator)->orWhereNull('operator_code'))
            ->orderByRaw('operator_code IS NULL')   // operator-specific first
            ->orderByDesc('version')
            ->first();

        if (! $table) {
            $this->warn("No DEPLOYED decision table for rule set [{$ruleSet}]".($operator ? " (operator {$operator})" : '').'.');

            return self::SUCCESS;
        }

        $this->table(['Field', 'Value'], [
            ['table_id', $table->table_id],
            ['rule_set', $table->rule_set],
            ['operator_code', $table->operator_code ?? '* (global)'],
            ['version', 'v'.$table->version],
            ['status', $table->status],
            ['hit_policy', $table->hit_policy ?? 'FIRST'],
            ['name', $table->name],
            ['description', $table->description ?? '—'],
            ['inputs', implode(', ', (array) ($table->inputs ?? [])) ?: '—'],
            ['default_output', json_encode($table->default_output ?? [], JSON_UNESCAPED_SLASHES)],
        ]);

        $ruleRows = [];
        foreach ((array) $table->rules as $i => $rule) {
            $ruleRows[] = [
                $rule['ruleId'] ?? 'R-UNSPECIFIED',
                json_encode($rule['when'] ?? [], JSON_UNESCAPED_SLASHES),
                json_encode($rule['then'] ?? [], JSON_UNESCAPED_SLASHES),
            ];
        }

        $this->info("Rules (evaluated top-down, hit policy {$table->hit_policy}):");
        if ($ruleRows) {
            $this->table(['ruleId', 'when (ALL must hold)', 'then'], $ruleRows);
        } else {
            $this->warn('This table has no rules — every evaluation returns the default output.');
        }

        return self::SUCCESS;
    }
}
