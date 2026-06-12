<?php

namespace App\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** SALES-01 commercial sales territory (DD §6.1). May align to a tech region but isn't required to. */
class SalesTerritory extends Model
{
    use HasPrefixedId;

    protected $table = 'sales_territory';
    protected $primaryKey = 'territory_id';
    protected string $idPrefix = 'terr';
    protected $guarded = [];
    protected $casts = ['active' => 'bool', 'metadata_json' => 'array'];

    public function getRouteKeyName(): string { return 'territory_id'; }
    protected static function booted(): void { static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode()); }

    public static function resolve(string $operator, string $code): ?self
    {
        return static::query()->where('operator_code', $operator)->where('territory_code', $code)->first();
    }
}
