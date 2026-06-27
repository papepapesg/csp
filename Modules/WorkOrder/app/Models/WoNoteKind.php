<?php

namespace Modules\WorkOrder\Models;
use Modules\WorkOrder\Models\WorkOrder;

use Illuminate\Database\Eloquent\Model;

/**
 * WO-01 §4.3 per-operator note-kind catalog. `schema_jsonb` holds a JSON Schema (Draft-07 keyword
 * subset) that the note's payload is validated against by App\Foundation\Validation\JsonSchemaValidator
 * in WorkOrderService::addNote(). It validates document SHAPE — type, required, enum, numeric ranges
 * (minimum/maximum/exclusiveMinimum/exclusiveMaximum/multipleOf), string pattern/length, nested
 * properties, additionalProperties. Cross-field / business rules (x > y, conditional logic) are NOT
 * expressible here and belong in the decision-table rule engine. `schema_jsonb = null` => free-text.
 */
class WoNoteKind extends Model
{
    protected $table = 'wo_note_kind_registry';

    public $incrementing = false;

    protected $primaryKey = null;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['schema_jsonb' => 'array', 'append_only' => 'boolean'];

    public static function resolve(string $operator, string $noteKind): ?self
    {
        return static::query()->where('operator_code', $operator)->where('note_kind', $noteKind)->first();
    }
}
