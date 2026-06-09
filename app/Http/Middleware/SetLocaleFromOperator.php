<?php

namespace App\Http\Middleware;

use App\Foundation\Models\OperatorConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * i18n: every request runs in the operator's configured locale (operator_config.
 * default_locale; en = Kenyan English baseline). Backend messages/dates/numbers
 * follow it; a user-level override may come via the X-Locale header (e.g. a
 * French-speaking agent on a Swahili deployment).
 */
class SetLocaleFromOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->header('X-Locale')
            ?? OperatorConfig::forOperator($request->user()?->operator_code)?->default_locale
            ?? config('app.locale', 'en');

        app()->setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }
}
