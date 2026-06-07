<?php

namespace Modules\Rules\Engine;

use App\Foundation\Errors\DomainException;
use App\Foundation\Rules\RuleEngine;
use App\Foundation\Support\Context;
use Modules\Rules\Models\DecisionTable;

/**
 * Data-driven rule engine (FOUNDATION_DROOLS as config).
 *
 * A rule package is a decision_table row (rule_set + operator + version). Each
 * rule has a stable ruleId, structured side-effect-free conditions, and a result
 * that is either a DecisionResult (attributes such as `eligible`, plus an
 * optional decisionCode) or a ValidationError ({field, message}). Evaluation
 * inserts facts, fires rules and returns results; empty results mean "pass"
 * (FOUNDATION_DROOLS §10, DROOLS-RES-1/3). Operator-specific packages override
 * the global default with no code change.
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
        return $this->assess($ruleSet, $facts)['decision'];
    }

    public function assess(string $ruleSet, array $facts): array
    {
        $table = $this->resolveTable($ruleSet, Context::operatorCode());

        if (! $table) {
            if (isset($this->fallbacks[$ruleSet])) {
                return ['decision' => ($this->fallbacks[$ruleSet])($facts), 'validationErrors' => [], 'firedRules' => []];
            }
            throw new DomainException('RULE_PACKAGE_NOT_FOUND', "No rule package or decision table for [{$ruleSet}].", 500);
        }

        return $this->fire($table, $facts);
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
     * @return array{decision: array<string,mixed>, validationErrors: array<int,array<string,mixed>>, firedRules: array<int,string>}
     */
    private function fire(DecisionTable $table, array $facts): array
    {
        $decision = $table->default_output ?? [];
        $errors = [];
        $fired = [];
        $first = ($table->hit_policy ?? 'FIRST') === 'FIRST';

        foreach ($table->rules as $rule) {
            if (! $this->matches($rule['when'] ?? [], $facts)) {
                continue;
            }

            $ruleId = $rule['ruleId'] ?? 'R-UNSPECIFIED';
            $fired[] = $ruleId;
            $then = $rule['then'] ?? [];

            // A ValidationError result (DROOLS-RES-1): { error: {field, message} }.
            if (isset($then['error'])) {
                $errors[] = [
                    'ruleId' => $ruleId,
                    'field' => $then['error']['field'] ?? null,
                    'message' => $then['error']['message'] ?? '',
                    'decisionCode' => $then['decisionCode'] ?? null,
                ];
                $decision = array_merge($decision, array_diff_key($then, ['error' => null]), ['ruleId' => $ruleId]);
            } else {
                // A DecisionResult: merge attributes, carry ruleId + decisionCode.
                $decision = array_merge($decision, $then, ['ruleId' => $ruleId]);
            }

            if ($first) {
                break;
            }
        }

        return ['decision' => $decision, 'validationErrors' => $errors, 'firedRules' => $fired];
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
                'not_in' => is_array($expected) && ! in_array($actual, $expected, true),
                default => false,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }
}
