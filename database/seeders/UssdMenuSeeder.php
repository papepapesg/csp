<?php

namespace Database\Seeders;

use App\Models\UssdMenuDefinition;
use Illuminate\Database\Seeder;

/**
 * FE-CH-USSD-01 default WIK menu tree (en + sw). The MVP scope: balance/debt, payment
 * instructions, subscription status, support ticket create/status, callback, language.
 * Menus are data — other operators override labels/options without code.
 */
class UssdMenuSeeder extends Seeder
{
    public function run(): void
    {
        $op = config('sophix.default_operator', 'WIK');

        $menus = [
            ['en', 'MAIN', "Welcome to SOPHIX\n1 Account balance\n2 Pay\n3 Subscription\n4 Support\n5 Request callback\n6 Language",
                ['1' => 'BALANCE', '2' => 'PAY', '3' => 'SUBSCRIPTION', '4' => 'SUPPORT', '5' => 'CALLBACK', '6' => 'LANGUAGE'], false],
            ['en', 'SUPPORT', "Support\n1 Create ticket\n2 Latest ticket status",
                ['1' => 'SUPPORT_CREATE', '2' => 'SUPPORT_STATUS'], true],
            ['en', 'LANGUAGE', "Language\n1 English\n2 Kiswahili",
                ['1' => 'LANG_EN', '2' => 'LANG_SW'], false],
            ['sw', 'MAIN', "Karibu SOPHIX\n1 Salio la akaunti\n2 Lipa\n3 Usajili\n4 Usaidizi\n5 Omba kupigiwa simu\n6 Lugha",
                ['1' => 'BALANCE', '2' => 'PAY', '3' => 'SUBSCRIPTION', '4' => 'SUPPORT', '5' => 'CALLBACK', '6' => 'LANGUAGE'], false],
            ['sw', 'SUPPORT', "Usaidizi\n1 Fungua tikiti\n2 Hali ya tikiti",
                ['1' => 'SUPPORT_CREATE', '2' => 'SUPPORT_STATUS'], true],
            ['sw', 'LANGUAGE', "Lugha\n1 Kiingereza\n2 Kiswahili",
                ['1' => 'LANG_EN', '2' => 'LANG_SW'], false],
        ];

        foreach ($menus as [$lang, $code, $text, $options, $requiresCustomer]) {
            UssdMenuDefinition::query()->updateOrCreate(
                ['operator_code' => $op, 'language_code' => $lang, 'menu_code' => $code],
                ['menu_text' => $text, 'options_json' => $options, 'requires_customer' => $requiresCustomer, 'enabled' => true],
            );
        }
    }
}
