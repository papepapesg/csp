<?php

namespace Modules\Notification\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** NOT-01 per-channel notification template (studio-authored). */
class NotificationTemplate extends Model
{
    use HasPrefixedId;

    public const DRAFT = 'DRAFT';

    public const ACTIVE = 'ACTIVE';

    public const RETIRED = 'RETIRED';

    protected $table = 'notification_template';

    protected $primaryKey = 'template_id';

    protected string $idPrefix = 'ntpl';

    protected $guarded = [];

    protected $casts = ['variables' => 'array'];

    public function getRouteKeyName(): string
    {
        return 'template_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    /** Resolve the ACTIVE template for (code, channel), preferring the locale then 'en'. */
    public static function resolve(string $operator, string $code, string $channel, string $locale = 'en'): ?self
    {
        return static::query()->where('operator_code', $operator)->where('template_code', $code)
            ->where('channel', $channel)->where('status', self::ACTIVE)
            ->orderByRaw("CASE WHEN locale = ? THEN 0 ELSE 1 END", [$locale])
            ->first();
    }
}
