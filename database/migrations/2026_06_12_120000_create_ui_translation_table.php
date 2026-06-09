<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * i18n resource catalog: every translatable string in the platform is a row,
 * addressed by (culture/locale, domain, section, key) and scoped to one operator
 * or to all ('*'). Operator rows override global rows at resolution time; the
 * base culture (en, Kenyan English) needs no rows — keys ARE the source text.
 * Managed from the Localization Studio; consumed by the backend translator and
 * the frontend t() through one merged, cached map per (operator, locale).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ui_translation', function (Blueprint $table) {
            $table->id();
            $table->string('operator_code')->default('*');   // '*' = all operators; else WIK/WUG/WTZ/YASSN override
            $table->string('locale', 12);                    // culture code: sw, fr, fr-SN, en-UG…
            $table->string('domain')->default('COMMON');     // functional area: NAV | COMMON | BILLING | TICKETING | BACKEND…
            $table->string('section')->default('general');   // page/area inside the domain (menu, cockpit, errors…)
            $table->string('key', 191);                      // source text or symbolic key
            $table->text('value');                           // the translation in this culture
            $table->string('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['operator_code', 'locale', 'domain', 'section', 'key']);
            $table->index(['locale', 'operator_code']);
            $table->index(['domain', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ui_translation');
    }
};
