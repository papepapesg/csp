<?php

namespace Modules\Notification\Rendering;

use Modules\Notification\Models\Template;

/**
 * Resolves (operator, format, purpose, locale) -> Template with the locale-fallback chain
 * (R-NOT-01-P-4). The operator's default locale comes from config; English is the final
 * fallback. Returns null when nothing matches (caller logs a render failure).
 */
class TemplateLookupService
{
    public function resolve(string $operator, string $format, string $purpose, string $locale): ?Template
    {
        $defaultLocale = config('sophix.notification.default_locale', 'en');

        return Template::resolve($operator, $format, $purpose, $locale, $defaultLocale);
    }
}
