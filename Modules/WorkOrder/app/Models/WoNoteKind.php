<?php

namespace Modules\WorkOrder\Models;

use Illuminate\Database\Eloquent\Model;

/** WO-01 §4.3 per-operator note-kind catalog (schema a note_kind validates against). */
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
