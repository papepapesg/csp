<?php

namespace Modules\Notification\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * NOT-01 unified template (R-NOT-01-D-2). One row per
 * (operator, template_format, template_purpose_code, locale, version). The same
 * purpose code (e.g. INVOICE_CYCLE_POSTPAID) decomposes into several format rows —
 * PDF, EMAIL_SUBJECT, EMAIL_HTML, EMAIL_TEXT, SMS_TEXT — each resolved independently.
 *
 * @property string $id
 * @property string $template_format
 * @property string $status
 */
class Template extends Model
{
    use HasPrefixedId;

    public const FORMAT_PDF = 'PDF';
    public const FORMAT_EMAIL_SUBJECT = 'EMAIL_SUBJECT';
    public const FORMAT_EMAIL_HTML = 'EMAIL_HTML';
    public const FORMAT_EMAIL_TEXT = 'EMAIL_TEXT';
    public const FORMAT_SMS_TEXT = 'SMS_TEXT';

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_ARCHIVED = 'ARCHIVED';
    public const STATUS_DISABLED = 'DISABLED';

    /** Template formats each channel needs to render a full message (R-NOT-01-R-1). */
    public const CHANNEL_FORMATS = [
        'EMAIL' => [self::FORMAT_EMAIL_SUBJECT, self::FORMAT_EMAIL_HTML, self::FORMAT_EMAIL_TEXT],
        'SMS' => [self::FORMAT_SMS_TEXT],
        // Text-shaped push channels reuse the SMS_TEXT body (terse). New channels declare
        // their required formats here; future rich formats (e.g. WHATSAPP_TEMPLATE_REF) add rows.
        'WHATSAPP' => [self::FORMAT_SMS_TEXT],
        'TELEGRAM' => [self::FORMAT_SMS_TEXT],
        'PUSH' => [self::FORMAT_SMS_TEXT],
    ];

    protected $table = 'template';

    protected $primaryKey = 'id';

    protected string $idPrefix = 'tpl';

    protected $guarded = [];

    protected $casts = [
        'placeholder_schema' => 'array',
        'sample_data' => 'array',
        'version' => 'int',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    /**
     * Resolve the highest-version ACTIVE template for a format + purpose, applying the
     * locale fallback chain (R-NOT-01-P-4): preferred locale → operator default → English.
     */
    public static function resolve(string $operator, string $format, string $purpose, string $locale, ?string $defaultLocale = null): ?self
    {
        $chain = array_values(array_unique(array_filter([$locale, $defaultLocale, 'en'])));
        foreach ($chain as $loc) {
            $hit = static::query()
                ->where('operator_code', $operator)
                ->where('template_format', $format)
                ->where('template_purpose_code', $purpose)
                ->where('locale', $loc)
                ->where('status', self::STATUS_ACTIVE)
                ->orderByDesc('version')
                ->first();
            if ($hit) {
                return $hit;
            }
        }

        return null;
    }

    /** Next version number for a (operator, format, purpose, locale) template family. */
    public static function nextVersion(string $operator, string $format, string $purpose, string $locale): int
    {
        return (int) static::query()
            ->where('operator_code', $operator)->where('template_format', $format)
            ->where('template_purpose_code', $purpose)->where('locale', $locale)
            ->max('version') + 1;
    }
}
