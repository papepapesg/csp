<?php

namespace Modules\Rules\Engine;

use App\Foundation\Errors\DomainException;
use App\Foundation\Rules\RuleEngine;
use App\Foundation\Support\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Drools/KIE Server driver for the RuleEngine contract (FOUNDATION_DROOLS §9).
 *
 * Active when SOPHIX_RULES_DRIVER=drools. Each call is a stateless KIE request:
 * insert one fact object, fire-all-rules, get-objects — and the §10 result
 * classes (ValidationError / DecisionResult) are mapped onto the same
 * {decision, validationErrors, firedRules} shape the native engine returns, so
 * callers cannot tell the drivers apart.
 *
 * Caller rules implemented here: pinned container ids from config, never
 * floating (DROOLS-VER-5); request timeout (DROOLS-CALL-1); summary logging
 * with correlation id, never raw fact payloads (DROOLS-CALL-2/3); KIE
 * unavailable = dependency failure unless the owning DD registered a fallback
 * (DROOLS-CALL-4).
 */
class DroolsRuleEngine implements RuleEngine
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
        $operator = $facts['operatorCode'] ?? Context::operatorCode();
        $binding = $this->resolveContainer($ruleSet, $operator);

        if (! $binding) {
            if (isset($this->fallbacks[$ruleSet])) {
                return ['decision' => ($this->fallbacks[$ruleSet])($facts), 'validationErrors' => [], 'firedRules' => []];
            }
            throw new DomainException('RULE_PACKAGE_NOT_FOUND', "No KIE container pinned for rule set [{$ruleSet}].", 500);
        }

        try {
            $response = Http::withBasicAuth((string) config('sophix.drools.user'), (string) config('sophix.drools.password'))
                ->timeout((int) config('sophix.drools.timeout_seconds', 3))
                ->withHeaders(['X-KIE-ContentType' => 'JSON'])
                ->acceptJson()
                ->post(rtrim((string) config('sophix.drools.base_url'), '/')."/services/rest/server/containers/instances/{$binding['container']}", [
                    'lookup' => $binding['lookup'],
                    'commands' => [
                        ['insert' => ['object' => [$binding['fact_class'] => $facts], 'out-identifier' => 'request']],
                        ['fire-all-rules' => ['max' => -1]],
                        ['get-objects' => ['out-identifier' => 'results']],
                    ],
                ]);
        } catch (\Throwable $e) {
            return $this->unavailable($ruleSet, $facts, $binding, $e->getMessage());
        }

        if (! $response->successful() || $response->json('type') !== 'SUCCESS') {
            return $this->unavailable($ruleSet, $facts, $binding, 'KIE response '.$response->status().' type '.$response->json('type'));
        }

        $objects = $response->json('result.execution-results.results.results.value', [])
            ?? $response->json('result.execution-results.results.value', []);

        $result = $this->mapResults(is_array($objects) ? $objects : []);

        // DROOLS-CALL-2/3: log the invocation summary, never the raw facts.
        Log::info('drools.evaluated', [
            'containerId' => $binding['container'],
            'lookup' => $binding['lookup'],
            'ruleSet' => $ruleSet,
            'correlationId' => Context::correlationId(),
            'validationErrors' => count($result['validationErrors']),
            'firedRules' => $result['firedRules'],
        ]);

        return $result;
    }

    /**
     * Pinned container binding for a rule set: exact key first, then longest
     * prefix; {operator} placeholder resolves to the lowercased operator code.
     *
     * @return array{container:string, lookup:string, fact_class:string}|null
     */
    private function resolveContainer(string $ruleSet, ?string $operator): ?array
    {
        $containers = (array) config('sophix.drools.containers', []);

        $match = $containers[$ruleSet] ?? null;
        if (! $match) {
            $best = '';
            foreach ($containers as $prefix => $binding) {
                if (str_starts_with($ruleSet, $prefix) && strlen($prefix) > strlen($best)) {
                    $best = $prefix;
                    $match = $binding;
                }
            }
        }
        if (! $match) {
            return null;
        }

        return [
            'container' => str_replace('{operator}', strtolower((string) ($operator ?: 'default')), $match['container']),
            'lookup' => $match['lookup'] ?? 'default-session',
            'fact_class' => $match['fact_class'] ?? 'com.sophix.common.facts.RequestFact',
        ];
    }

    /**
     * Map KIE result objects (§10) to the engine contract. Each object is
     * {fqcn: props}: *.ValidationError collects, *.DecisionResult merges its
     * decisionCode + attributes into the decision; anything else is an
     * integration warning (§13: unknown result class → alert).
     *
     * @param  array<int,array<string,mixed>>  $objects
     * @return array{decision: array<string,mixed>, validationErrors: array<int,array<string,mixed>>, firedRules: array<int,string>}
     */
    private function mapResults(array $objects): array
    {
        $decision = [];
        $errors = [];
        $fired = [];

        foreach ($objects as $object) {
            foreach ((array) $object as $class => $props) {
                $ruleId = $props['ruleId'] ?? null;
                if ($ruleId) {
                    $fired[] = $ruleId;
                }
                if (str_ends_with($class, 'ValidationError')) {
                    $errors[] = [
                        'ruleId' => $ruleId ?? 'R-UNSPECIFIED',
                        'field' => $props['field'] ?? null,
                        'message' => $props['message'] ?? '',
                        'decisionCode' => $props['decisionCode'] ?? null,
                    ];
                } elseif (str_ends_with($class, 'DecisionResult')) {
                    $decision = array_merge($decision, (array) ($props['attributes'] ?? []), array_filter([
                        'decisionCode' => $props['decisionCode'] ?? null,
                        'ruleId' => $ruleId,
                    ]));
                } else {
                    Log::warning('drools.unknown_result_class', ['class' => $class]);
                }
            }
        }

        return ['decision' => $decision, 'validationErrors' => $errors, 'firedRules' => array_values(array_unique($fired))];
    }

    /** DROOLS-CALL-4: KIE unavailable → registered fallback, else dependency failure. */
    private function unavailable(string $ruleSet, array $facts, array $binding, string $reason): array
    {
        Log::warning('drools.unavailable', [
            'containerId' => $binding['container'],
            'ruleSet' => $ruleSet,
            'correlationId' => Context::correlationId(),
            'reason' => $reason,
        ]);

        if (isset($this->fallbacks[$ruleSet])) {
            return ['decision' => ($this->fallbacks[$ruleSet])($facts), 'validationErrors' => [], 'firedRules' => []];
        }

        throw DomainException::dependencyUnavailable("Rules engine (KIE) unavailable for [{$ruleSet}].");
    }
}
