<?php

namespace App\Foundation\Rules;

/**
 * Policy/decision engine contract (FOUNDATION_DROOLS).
 *
 * Rules are side-effect free: they receive facts and return a decision. They
 * MUST NOT call HTTP, query databases, publish events or write files (HLD §6.5).
 * The native driver evaluates registered rule sets in-process; a Drools driver
 * can forward to a KIE server when SOPHIX_RULES_DRIVER=drools.
 */
interface RuleEngine
{
    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed> decision
     */
    public function evaluate(string $ruleSet, array $facts): array;

    /**
     * Register a side-effect-free rule set.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $resolver
     */
    public function register(string $ruleSet, callable $resolver): void;
}
