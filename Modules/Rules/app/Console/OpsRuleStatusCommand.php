<?php

namespace Modules\Rules\Console;

use Illuminate\Console\Command;
use Modules\Rules\Models\DecisionTable;

/**
 * Ops review: a one-glance inventory of which decision-table policy is LIVE per
 * rule set (read-only). For each deployed table it shows the rule_set, operator
 * scope, deployed version, status and hit policy — so ops can see which policy
 * an operator is actually running before touching anything.
 */
class OpsRuleStatusCommand extends Command
{
    protected $signature = 'sophix:rules:ops-status {--operator= : Scope to one operator code} {--rule-set= : Scope to one rule set}';

    protected $description = 'Review: deployed decision tables and their live version + status per operator (read-only)';

    public function handle(): int
    {
        $operator = $this->option('operator');
        $ruleSet = $this->option('rule-set');

        $tables = DecisionTable::query()
            ->where('status', DecisionTable::DEPLOYED)
            ->when($ruleSet, fn ($q) => $q->where('rule_set', $ruleSet))
            ->when($operator, fn ($q) => $q->where('operator_code', $operator))
            ->orderBy('rule_set')
            ->orderByRaw('operator_code IS NULL')
            ->orderByDesc('version')
            ->get();

        if ($tables->isEmpty()) {
            $this->warn('No DEPLOYED decision tables match the given scope.');

            return self::SUCCESS;
        }

        $rows = $tables->map(fn (DecisionTable $t) => [
            $t->rule_set,
            $t->operator_code ?? '* (global)',
            'v'.$t->version,
            $t->status,
            $t->hit_policy ?? 'FIRST',
            count((array) $t->rules),
            $t->name,
        ])->all();

        $this->info('Deployed decision tables'.($operator ? " — operator {$operator}" : ' — all operators'));
        $this->table(['Rule set', 'Operator', 'Version', 'Status', 'Hit policy', 'Rules', 'Name'], $rows);
        $this->line('Inspect one: sophix:rules:table-show <rule_set> [--operator=] · debug: sophix:rules:evaluate <rule_set> --facts=\'{...}\'');

        return self::SUCCESS;
    }
}
