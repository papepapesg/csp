<?php

namespace Modules\Notification\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** NOT-01 designable invoice layout (studio-authored). */
class InvoiceTemplate extends Model
{
    use HasPrefixedId;

    public const DRAFT = 'DRAFT';

    public const ACTIVE = 'ACTIVE';

    protected $table = 'invoice_template';

    protected $primaryKey = 'template_id';

    protected string $idPrefix = 'itpl';

    protected $guarded = [];

    protected $casts = ['layout' => 'array'];

    public function getRouteKeyName(): string
    {
        return 'template_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
