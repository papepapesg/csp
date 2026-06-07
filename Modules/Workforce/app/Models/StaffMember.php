<?php

namespace Modules\Workforce\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** EM-02 staff_member registry row. */
class StaffMember extends Model
{
    use HasPrefixedId;

    protected $table = 'staff_member';

    protected $primaryKey = 'staff_id';

    protected string $idPrefix = 'stf';

    protected $guarded = [];

    protected $casts = ['skills' => 'array'];

    public function getRouteKeyName(): string
    {
        return 'staff_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $x) => $x->operator_code ??= Context::operatorCode());
    }
}
