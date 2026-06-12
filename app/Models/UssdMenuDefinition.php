<?php

namespace App\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** FE-CH-USSD-01 configurable menu (per operator + language). Menus are data, not code. */
class UssdMenuDefinition extends Model
{
    use HasPrefixedId;

    protected $table = 'ussd_menu_definition';
    protected $primaryKey = 'menu_def_id';
    protected string $idPrefix = 'umd';
    protected $guarded = [];
    protected $casts = ['options_json' => 'array', 'requires_customer' => 'bool', 'enabled' => 'bool'];

    protected static function booted(): void { static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode()); }

    public static function resolve(string $operator, string $language, string $menuCode): ?self
    {
        return static::query()->where('operator_code', $operator)->where('menu_code', $menuCode)->where('enabled', true)
            ->orderByRaw('CASE WHEN language_code = ? THEN 0 ELSE 1 END', [$language])->first();
    }
}
