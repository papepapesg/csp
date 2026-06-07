<?php

namespace Modules\Workflow\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/**
 * A deployed flow definition (graph of nodes/edges). FOUNDATION_CAMUNDA.
 *
 * @property string $definition_id
 * @property array $graph
 */
class ProcessDefinition extends Model
{
    use HasPrefixedId;

    public const DRAFT = 'DRAFT';

    public const DEPLOYED = 'DEPLOYED';

    public const RETIRED = 'RETIRED';

    protected $table = 'process_definition';

    protected $primaryKey = 'definition_id';

    protected string $idPrefix = 'pdef';

    protected $guarded = [];

    protected $casts = ['graph' => 'array', 'deployed_at' => 'datetime'];

    public function getRouteKeyName(): string
    {
        return 'definition_id';
    }

    /** @return array<int,array<string,mixed>> */
    public function nodes(): array
    {
        return $this->graph['nodes'] ?? [];
    }

    /** @return array<int,array<string,mixed>> */
    public function edges(): array
    {
        return $this->graph['edges'] ?? [];
    }

    public function node(string $id): ?array
    {
        foreach ($this->nodes() as $n) {
            if (($n['id'] ?? null) === $id) {
                return $n;
            }
        }

        return null;
    }

    /** @return array<int,array<string,mixed>> outgoing edges from $nodeId */
    public function outgoing(string $nodeId): array
    {
        return array_values(array_filter($this->edges(), fn ($e) => ($e['source'] ?? null) === $nodeId));
    }

    public function startNode(): ?array
    {
        foreach ($this->nodes() as $n) {
            if (($n['type'] ?? null) === 'startEvent') {
                return $n;
            }
        }

        return null;
    }
}
