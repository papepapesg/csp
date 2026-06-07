<?php

namespace App\Foundation\Rules;

use App\Foundation\Errors\DomainException;
use App\Foundation\Errors\ErrorCode;

/**
 * In-process rule engine (default driver).
 *
 * Modules register named, side-effect-free rule sets during boot (typically in
 * their service provider). Evaluation simply runs the resolver against supplied
 * facts. This satisfies "Drools is used only for configurable business policy,
 * not fixed validation" (MVP baseline §1.4) while staying all-Laravel.
 */
class NativeRuleEngine implements RuleEngine
{
    /** @var array<string, callable(array<string, mixed>): array<string, mixed>> */
    private array $ruleSets = [];

    public function register(string $ruleSet, callable $resolver): void
    {
        $this->ruleSets[$ruleSet] = $resolver;
    }

    public function evaluate(string $ruleSet, array $facts): array
    {
        if (! isset($this->ruleSets[$ruleSet])) {
            throw new DomainException(
                ErrorCode::INTERNAL_ERROR,
                "Unknown rule set [{$ruleSet}].",
                500,
            );
        }

        return ($this->ruleSets[$ruleSet])($facts);
    }
}
