<?php

namespace App\Foundation\Files;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** FOUNDATION_FILE_STORAGE file registry row. */
class FileObject extends Model
{
    use HasPrefixedId;

    protected $table = 'file_object';

    protected $primaryKey = 'file_id';

    protected string $idPrefix = 'file';

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'file_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
