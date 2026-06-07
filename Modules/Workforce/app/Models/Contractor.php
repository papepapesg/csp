<?php

namespace Modules\Workforce\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** EM-02 contractor registry row. */
class Contractor extends Model
{
    use HasPrefixedId;

    protected $table = 'contractor';

    protected $primaryKey = 'contractor_id';

    protected string $idPrefix = 'con';

    protected $guarded = [];

    protected $casts = ['skills' => 'array'];

    public function getRouteKeyName(): string
    {
        return 'contractor_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $x) => $x->operator_code ??= Context::operatorCode());
    }
}
