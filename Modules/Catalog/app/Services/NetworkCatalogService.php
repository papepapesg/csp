<?php

namespace Modules\Catalog\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Support\Context;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\NetworkNode;

/**
 * RLM-CFG-01 network_node catalog rules (R-RLM-CFG-01-N-*). Nodes form a walkable parent
 * chain (no cycles, N-4); a node referenced by an active HomePass network_path cannot be
 * retired (N-5). The catalog is admin-managed reference data.
 */
class NetworkCatalogService
{
    /** @param array<string,mixed> $data code, type, name, parent_node_code? */
    public function createNode(array $data): NetworkNode
    {
        $operator = Context::operatorCode();
        // R-RLM-CFG-01-N-3: type from the controlled enum.
        if (! in_array($data['type'] ?? null, NetworkNode::TYPES, true)) {
            throw DomainException::ruleRejected('INVALID_NODE_TYPE', "Node type '{$data['type']}' is not a valid network node type.");
        }
        // R-RLM-CFG-01-N-4: a declared parent must exist; the chain must not cycle.
        if (! empty($data['parent_node_code'])) {
            $parent = NetworkNode::query()->where('operator_code', $operator)->where('code', $data['parent_node_code'])->first();
            if (! $parent) {
                throw DomainException::ruleRejected('UNKNOWN_PARENT_NODE', "Parent node '{$data['parent_node_code']}' does not exist.");
            }
            $this->assertNoCycle($operator, $data['code'], $data['parent_node_code']);
        }

        return NetworkNode::query()->create($data + ['operator_code' => $operator]);
    }

    /** R-RLM-CFG-01-N-5: a node referenced by an active HomePass network_path cannot be retired. */
    public function retireNode(NetworkNode $node): NetworkNode
    {
        $referenced = DB::table('homepass')
            ->where('operator_code', $node->operator_code)
            ->whereJsonContains('network_path->nodes', [['code' => $node->code]])
            ->exists();
        // Fallback portable check (whereJsonContains shape varies): scan node codes.
        if (! $referenced) {
            $referenced = DB::table('homepass')->where('operator_code', $node->operator_code)
                ->whereNotNull('network_path')
                ->where('network_path', 'like', '%"'.$node->code.'"%')->exists();
        }
        if ($referenced) {
            throw DomainException::ruleRejected('NODE_REFERENCED', "Node {$node->code} is referenced by a HomePass network path and cannot be retired.");
        }
        $node->update(['status' => 'RETIRED']);

        return $node;
    }

    private function assertNoCycle(string $operator, string $code, string $parentCode): void
    {
        $seen = [$code];
        $current = $parentCode;
        $guard = 0;
        while ($current !== null && $guard++ < 100) {
            if (in_array($current, $seen, true)) {
                throw DomainException::ruleRejected('NODE_PARENT_CYCLE', 'Network node parent chain would form a cycle.');
            }
            $seen[] = $current;
            $current = NetworkNode::query()->where('operator_code', $operator)->where('code', $current)->value('parent_node_code');
        }
    }
}
