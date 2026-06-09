<?php

namespace Database\Seeders;

use App\Foundation\Models\OperatorConfig;
use Illuminate\Database\Seeder;

/** Default operator deployment configs (identity/locale/currency/theme/logs). */
class OperatorConfigSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['WIK', 'Wananchi Kenya', 'en', 'KES', 'Africa/Nairobi', '#4f46e5'],
            ['WUG', 'Wananchi Uganda', 'en', 'UGX', 'Africa/Kampala', '#0d9488'],
            ['WTZ', 'Wananchi Tanzania', 'sw', 'TZS', 'Africa/Dar_es_Salaam', '#b45309'],
            ['YASSN', 'Yassir Senegal', 'fr', 'XOF', 'Africa/Dakar', '#be123c'],
        ];
        foreach ($rows as [$code, $name, $locale, $currency, $tz, $color]) {
            OperatorConfig::query()->updateOrCreate(
                ['operator_code' => $code],
                ['display_name' => $name, 'default_locale' => $locale, 'currency_code' => $currency,
                 'timezone' => $tz, 'theme_primary_color' => $color, 'log_level' => 'info', 'updated_by' => 'seed'],
            );
        }
    }
}
