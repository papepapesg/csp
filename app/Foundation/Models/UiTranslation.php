<?php

namespace App\Foundation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One translated string: (locale/culture, domain, section, key) → value, scoped
 * to one operator or global ('*'). See the Localization Studio (/i18n/studio).
 */
class UiTranslation extends Model
{
    /** Scope marker for rows that apply to every operator. */
    public const ALL_OPERATORS = '*';

    protected $table = 'ui_translation';

    protected $guarded = [];
}
