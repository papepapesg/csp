<?php

namespace Modules\Workflow\Engine;

/**
 * Validates a flow graph against the registered step signatures BEFORE it deploys
 * (the studio's "issues" panel + a hard gate on deploy). Catches the mistakes the
 * visual editor must not let through: dangling edges, unknown/typo'd topics, a
 * required input left unbound, or a data wire pointing at an output the source
 * node does not actually produce. Pure in-process reflection over the registry —
 * no DB, no side effects.
 */
class GraphValidator
{
    public function __construct(private readonly TaskRegistry $registry) {}

    /**
     * @param  array<string,mixed>  $graph  { nodes:[...], edges:[...] }
     * @return array<int,string>  human-readable errors; [] means valid
     */
    public function validate(array $graph): array
    {
        $nodes = $graph['nodes'] ?? [];
        $edges = $graph['edges'] ?? [];
        if (! is_array($nodes) || ! $nodes) {
            return ['Flow has no nodes.'];
        }

        $errors = [];
        $byId = [];
        foreach ($nodes as $n) {
            $byId[$n['id'] ?? ''] = $n;
        }

        // Directed adjacency (source -> [targets]) for upstream-reachability checks.
        $adj = [];
        foreach ($edges as $e) {
            $src = $e['source'] ?? null;
            $tgt = $e['target'] ?? null;
            if ($src !== null && $tgt !== null) {
                $adj[$src][] = $tgt;
            }
        }

        // Structural: exactly one start, at least one end.
        $starts = array_filter($nodes, fn ($n) => ($n['type'] ?? '') === 'startEvent');
        if (count($starts) !== 1) {
            $errors[] = 'Flow must have exactly one start event (found '.count($starts).').';
        }
        if (! array_filter($nodes, fn ($n) => ($n['type'] ?? '') === 'endEvent')) {
            $errors[] = 'Flow has no end event — instances could never complete.';
        }

        // Edges must reference existing nodes.
        foreach ($edges as $e) {
            $eid = $e['id'] ?? '?';
            foreach (['source', 'target'] as $side) {
                $ref = $e[$side] ?? null;
                if ($ref !== null && ! isset($byId[$ref])) {
                    $errors[] = "Edge [{$eid}] {$side} points to unknown node [{$ref}].";
                }
            }
        }

        // Per service task: topic must be registered; required inputs must be bound;
        // any data wire must reference a real output of an existing node.
        foreach ($nodes as $n) {
            if (($n['type'] ?? 'serviceTask') !== 'serviceTask') {
                continue;
            }
            $id = $n['id'] ?? '?';
            $topic = $n['data']['topic'] ?? null;
            if (! $topic) {
                $errors[] = "Service task [{$id}] has no topic.";

                continue;
            }
            if (! $this->registry->has($topic)) {
                $errors[] = "Service task [{$id}] uses unknown step '{$topic}' (no handler registered).";

                continue;
            }

            $sig = $this->registry->signature($topic);
            $config = $n['data']['config'] ?? [];
            $mappings = $n['data']['inputMappings'] ?? [];

            foreach ($sig['inputs'] as $in) {
                $name = $in['name'];
                $required = (bool) ($in['required'] ?? false);
                $hasDefault = array_key_exists('default', $in) && $in['default'] !== null;
                $bound = array_key_exists($name, $mappings) || array_key_exists($name, $config) || $hasDefault;

                if ($required && ! $bound) {
                    $errors[] = "Node [{$id}] ('{$sig['label']}') is missing required input '{$name}'.";
                }

                if (array_key_exists($name, $mappings)) {
                    $errors = array_merge($errors, $this->validateMapping($id, $name, $mappings[$name], $byId, $adj));
                }
            }
        }

        return array_values($errors);
    }

    /**
     * A wire { from: "sourceNode.port" } must point at a node that (a) exists,
     * (b) runs UPSTREAM of the consumer — so the value is produced before it is
     * read (single-token flow) — and (c) declares that output. { var: ... } /
     * { const: ... } and flat refs are always allowed (no producer to cross-check).
     *
     * @param  array<string,array<string,mixed>>  $byId
     * @param  array<string,array<int,string>>  $adj
     * @return array<int,string>
     */
    private function validateMapping(string $nodeId, string $input, mixed $mapping, array $byId, array $adj): array
    {
        $from = is_array($mapping) ? ($mapping['from'] ?? null) : (is_string($mapping) ? $mapping : null);
        if (! $from || ! str_contains($from, '.')) {
            return []; // const/var mapping, or a flat variable reference — nothing to cross-check
        }

        [$srcNode, $srcPort] = explode('.', $from, 2);
        if (! isset($byId[$srcNode])) {
            return ["Node [{$nodeId}] input '{$input}' is wired from unknown node [{$srcNode}]."];
        }

        // Producer/consumer ordering: the source must be able to reach the consumer.
        if ($srcNode === $nodeId || ! $this->reaches($srcNode, $nodeId, $adj)) {
            return ["Node [{$nodeId}] input '{$input}' is wired from [{$srcNode}], which is not upstream of it — the value would not exist yet."];
        }

        $srcTopic = $byId[$srcNode]['data']['topic'] ?? null;
        if (! $srcTopic) {
            return [];
        }
        $outs = array_column($this->registry->signature($srcTopic)['outputs'], 'name');
        if ($outs && ! in_array($srcPort, $outs, true)) {
            return ["Node [{$nodeId}] input '{$input}' is wired from [{$srcNode}.{$srcPort}], but that step does not produce '{$srcPort}'."];
        }

        return [];
    }

    /**
     * Can $from reach $to by following directed edges? (BFS; graphs are small.)
     *
     * @param  array<string,array<int,string>>  $adj
     */
    private function reaches(string $from, string $to, array $adj): bool
    {
        $seen = [];
        $queue = $adj[$from] ?? [];
        while ($queue) {
            $node = array_shift($queue);
            if ($node === $to) {
                return true;
            }
            if (isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;
            foreach ($adj[$node] ?? [] as $next) {
                $queue[] = $next;
            }
        }

        return false;
    }
}
