<?php

namespace Modules\Notification\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** A stored rendered presentation of an entity (NOT-01 document layer). */
class RenderedArtifact extends Model
{
    use HasPrefixedId;

    public const RENDERED = 'RENDERED';

    protected $table = 'rendered_artifact';

    protected $primaryKey = 'artifact_id';

    protected string $idPrefix = 'rart';

    protected $guarded = [];

    protected $casts = ['rendered_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
