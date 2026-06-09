<?php

namespace App\Foundation\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Foundation\I18n\TranslationService;
use App\Foundation\Models\UiTranslation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Localization Studio API: manage the i18n resource catalog by culture (locale),
 * domain and section, globally or per operator; serve the merged resource map
 * any client consumes at runtime.
 */
class I18nController extends ApiController
{
    public function __construct(private readonly TranslationService $translations) {}

    /** GET /api/i18n/resources?locale= — the merged map for runtime consumption. */
    public function resources(Request $request): JsonResponse
    {
        $locale = (string) $request->query('locale', app()->getLocale());

        return ApiResponse::item([
            'locale' => $locale,
            'resources' => $this->translations->resources($locale, $request->user()?->operator_code),
        ]);
    }

    /** GET /api/i18n/meta — cultures, domains and sections for the studio pickers. */
    public function meta(): JsonResponse
    {
        return ApiResponse::item([
            'locales' => $this->translations->locales(),
            'domains' => UiTranslation::query()->distinct()->orderBy('domain')->pluck('domain'),
            'sections' => UiTranslation::query()->distinct()->orderBy('section')->pluck('section'),
        ]);
    }

    /** GET /api/i18n/translations?locale=&domain=&section=&q=&operatorCode= — the catalog rows. */
    public function index(Request $request): JsonResponse
    {
        $rows = UiTranslation::query()
            ->when($request->query('locale'), fn ($q, $l) => $q->where('locale', $l))
            ->when($request->query('domain'), fn ($q, $d) => $q->where('domain', $d))
            ->when($request->query('section'), fn ($q, $s) => $q->where('section', $s))
            ->when($request->query('operatorCode'), fn ($q, $o) => $q->where('operator_code', $o))
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('key', 'ilike', "%{$term}%")->orWhere('value', 'ilike', "%{$term}%")))
            ->orderBy('domain')->orderBy('section')->orderBy('key')
            ->limit(1000)->get();

        return ApiResponse::item(['items' => $rows]);
    }

    /** POST /api/i18n/translations — bulk upsert (one or many rows in one call). */
    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.locale' => ['required', 'string', 'max:12'],
            'items.*.key' => ['required', 'string', 'max:191'],
            'items.*.value' => ['required', 'string'],
            'items.*.domain' => ['nullable', 'string', 'max:64'],
            'items.*.section' => ['nullable', 'string', 'max:64'],
            'items.*.operator_code' => ['nullable', 'string', 'max:16'],
        ]);

        $saved = [];
        foreach ($data['items'] as $item) {
            $row = UiTranslation::query()->updateOrCreate(
                [
                    'operator_code' => $item['operator_code'] ?? UiTranslation::ALL_OPERATORS,
                    'locale' => $item['locale'],
                    'domain' => $item['domain'] ?? 'COMMON',
                    'section' => $item['section'] ?? 'general',
                    'key' => $item['key'],
                ],
                ['value' => $item['value'], 'updated_by' => $request->user()?->uid ?? $request->user()?->email],
            );
            $this->translations->evict($row->locale, $row->operator_code);
            $saved[] = $row;
        }

        return ApiResponse::item(['items' => $saved], 201);
    }

    /** DELETE /api/i18n/translations/{translation} */
    public function destroy(UiTranslation $translation): JsonResponse
    {
        $translation->delete();
        $this->translations->evict($translation->locale, $translation->operator_code);

        return ApiResponse::item(['deleted' => true]);
    }
}
