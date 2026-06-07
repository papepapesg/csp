<?php

namespace Modules\Ilm\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** EM-03 CVM activity (retention/recovery/win-back offer). */
class CvmActivity extends Model
{
    use HasPrefixedId;

    public const OFFERED = 'OFFERED';

    public const ACCEPTED = 'ACCEPTED';

    public const DECLINED = 'DECLINED';

    public const EXPIRED = 'EXPIRED';

    protected $table = 'cvm_activity';

    protected $primaryKey = 'activity_id';

    protected string $idPrefix = 'cvm';

    protected $guarded = [];

    protected $casts = ['offer_details' => 'array', 'expires_at' => 'datetime', 'decided_at' => 'datetime'];

    public function getRouteKeyName(): string
    {
        return 'activity_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
