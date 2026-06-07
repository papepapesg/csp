<?php

namespace App\Foundation\Rules;

/**
 * Business rules engine contract (FOUNDATION_DROOLS).
 *
 * Rules are side-effect free: facts in, result facts out. They MUST NOT call
 * HTTP, query databases, publish events or write files (HLD §6.5). Rule packages
 * are named per the design convention `rules.<domain>.<kind>` and scoped by
 * operator + version; every result carries a stable ruleId (DROOLS-RES-1).
 *
 * The native driver evaluates decision tables stored as data; a Drools driver
 * forwards to a KIE server when SOPHIX_RULES_DRIVER=drools (same contract).
 */
interface RuleEngine
{
    /**
     * Full assessment of a rule package against facts (FOUNDATION_DROOLS §10).
     *
     * @param  array<string, mixed>  $facts
     * @return array{decision: array<string,mixed>, validationErrors: array<int, array{ruleId:string, field:?string, message:string, decisionCode:?string}>, firedRules: array<int,string>}
     */
    public function assess(string $ruleSet, array $facts): array;

    /**
     * Convenience: return just the merged decision attributes (e.g. ['eligible'=>true]).
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function evaluate(string $ruleSet, array $facts): array;

    /**
     * Register a side-effect-free fallback rule set (used only when no decision
     * table exists for the key — eases migration).
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $resolver
     */
    public function register(string $ruleSet, callable $resolver): void;
}
