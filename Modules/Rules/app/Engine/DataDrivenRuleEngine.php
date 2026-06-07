<?php

namespace Modules\Rules\Engine;

use App\Foundation\Errors\DomainException;
use App\Foundation\Rules\RuleEngine;
use App\Foundation\Support\Context;
use Modules\Rules\Models\DecisionTable;

/**
 * Data-driven rule engine (FOUNDATION_DROOLS as config). Policy lives in
 * decision_table rows, not code: evaluate() resolves the deployed table for a
 * rule set (operator-specific first, else global), runs each rule's structured,
 * side-effect-free conditions against the facts, and returns outputs per the
 * hit policy. A registered closure is used only as a fallback when no table
 * exists, so legacy/programmatic rules keep working during migration.
 */
class DataDrivenRuleEngine implements RuleEngine
{
    /** @var array<string, callable(array<string,mixed>): array<string,mixed>> */
    private array $fallbacks = [];

    public function register(string $ruleSet, callable $resolver): void
    {
        $this->fallbacks[$ruleSet] = $resolver;
    }

    public function evaluate(string $ruleSet, array $facts): array
    {
        $table = $this->resolveTable($ruleSet, Context::operatorCode());

        if (! $table) {
            if (isset($this->fallbacks[$ruleSet])) {
                return ($this->fallbacks[$ruleSet])($facts);
            }
            throw new DomainException('RULE_SET_NOT_FOUND', "No decision table or rule for [{$ruleSet}].", 500);
        }

        return $this->run($table, $facts);
    }

    private function resolveTable(string $ruleSet, ?string $operator): ?DecisionTable
    {
        return DecisionTable::query()
            ->where('rule_set', $ruleSet)
            ->where('status', DecisionTable::DEPLOYED)
            ->where(fn ($q) => $q->where('operator_code', $operator)->orWhereNull('operator_code'))
            ->orderByRaw('operator_code IS NULL')   // operator-specific first
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    private function run(DecisionTable $table, array $facts): array
    {
        $collected = [];

        foreach ($table->rules as $rule) {
            $conditions = $rule['when'] ?? [];
            if ($this->matches($conditions, $facts)) {
                $outputs = $rule['then'] ?? [];
                if (($table->hit_policy ?? 'FIRST') === 'FIRST') {
                    return $outputs;
                }
                $collected[] = $outputs;
            }
        }

        if (($table->hit_policy ?? 'FIRST') === 'COLLECT') {
            return ['matches' => $collected];
        }

        return $table->default_output ?? [];
    }

    /**
     * @param  array<int,array<string,mixed>>  $conditions  ALL must hold (AND)
     * @param  array<string,mixed>  $facts
     */
    private function matches(array $conditions, array $facts): bool
    {
        foreach ($conditions as $cond) {
            $actual = data_get($facts, $cond['var'] ?? '');
            $expected = $cond['value'] ?? null;
            $ok = match ($cond['op'] ?? 'eq') {
                'eq' => $actual == $expected,
                'neq' => $actual != $expected,
                'gt' => $actual > $expected,
                'gte' => $actual >= $expected,
                'lt' => $actual < $expected,
                'lte' => $actual <= $expected,
                'truthy' => (bool) $actual === true,
                'falsy' => (bool) $actual === false,
                'in' => is_array($expected) && in_array($actual, $expected, true),
                default => false,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }
}
