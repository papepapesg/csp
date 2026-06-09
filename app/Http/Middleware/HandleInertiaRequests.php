<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // Operator deployment config: views skin/localise per operator at runtime.
            'operatorConfig' => fn () => $request->user()
                ? \App\Foundation\Models\OperatorConfig::forOperator($request->user()->operator_code)
                : null,
            // i18n resources for the request locale (studio-managed, cached merge
            // of global + operator rows) — consumed by useI18n().t().
            'i18nResources' => fn () => app(\App\Foundation\I18n\TranslationService::class)
                ->resources(app()->getLocale(), $request->user()?->operator_code),
            'auth' => [
                'user' => $request->user(),
            ],
        ];
    }
}
