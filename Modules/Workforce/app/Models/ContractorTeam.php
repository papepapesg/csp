<?php

namespace Modules\Workforce\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** EM-02 contractor_team registry row. */
class ContractorTeam extends Model
{
    use HasPrefixedId;

    protected $table = 'contractor_team';

    protected $primaryKey = 'team_id';

    protected string $idPrefix = 'team';

    protected $guarded = [];

    protected $casts = ['skills' => 'array'];

    public function getRouteKeyName(): string
    {
        return 'team_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $x) => $x->operator_code ??= Context::operatorCode());
    }
}
