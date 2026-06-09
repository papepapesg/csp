<?php

namespace App\Foundation\I18n;

use App\Foundation\Cache\SophixCache;
use App\Foundation\Models\OperatorConfig;
use App\Foundation\Models\UiTranslation;
use Illuminate\Support\Facades\Lang;

/**
 * i18n resource resolution. The ui_translation catalog is the source of truth
 * for every culture's strings; this service merges it into one flat map per
 * (operator, locale) — global '*' rows first, operator rows overriding — and
 * caches the result (cache-aside, event-free eviction on every studio write).
 *
 * Consumers:
 *  - backend: SetLocaleFromOperator pushes the map into Laravel's translator
 *    (so __()/validation messages honour studio edits without a deploy);
 *  - frontend: HandleInertiaRequests shares it as `i18nResources` for t().
 */
class TranslationService
{
    public function __construct(private readonly SophixCache $cache) {}

    /** Merged key → value map for one operator + locale (cached). */
    public function resources(string $locale, ?string $operator = null): array
    {
        $operator = $operator ?: UiTranslation::ALL_OPERATORS;

        return $this->cache->remember('i18n', 'resources', "{$operator}:{$locale}", SophixCache::TTL_CATALOG, function () use ($locale, $operator) {
            $rows = UiTranslation::query()
                ->where('locale', $locale)
                ->whereIn('operator_code', [UiTranslation::ALL_OPERATORS, $operator])
                ->orderByRaw("case when operator_code = '*' then 0 else 1 end") // operator rows override global
                ->get(['key', 'value']);

            return $rows->pluck('value', 'key')->all();
        }) ?? [];
    }

    /**
     * Load the catalog into Laravel's translator for the current request so
     * backend __() strings honour studio-managed resources. The file-based JSON
     * lines load first; catalog rows override them.
     */
    public function applyToTranslator(string $locale, ?string $operator = null): void
    {
        $lines = $this->resources($locale, $operator);
        if ($lines === []) {
            return;
        }

        $translator = Lang::getFacadeRoot();
        $translator->load('*', '*', $locale); // ensure file JSON is in before we merge over it
        $translator->addLines(
            collect($lines)->mapWithKeys(fn ($value, $key) => ["*.{$key}" => $value])->all(),
            $locale,
        );
    }

    /** The cultures the studio offers: configured + already-translated ones. */
    public function locales(): array
    {
        return UiTranslation::query()->distinct()->pluck('locale')
            ->merge(OperatorConfig::query()->pluck('default_locale'))
            ->push('en', 'sw', 'fr')
            ->unique()->sort()->values()->all();
    }

    /** Evict the merged maps that contain this row (global rows touch all operators). */
    public function evict(string $locale, string $operatorCode): void
    {
        if ($operatorCode === UiTranslation::ALL_OPERATORS) {
            $operators = OperatorConfig::query()->pluck('operator_code')
                ->push(UiTranslation::ALL_OPERATORS)
                ->merge(UiTranslation::query()->distinct()->pluck('operator_code'));
        } else {
            $operators = collect([$operatorCode]);
        }

        foreach ($operators->unique() as $operator) {
            $this->cache->evict('i18n', 'resources', "{$operator}:{$locale}");
        }
    }
}
