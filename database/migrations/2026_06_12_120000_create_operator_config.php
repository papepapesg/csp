<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator deployment configuration (EM/FOUNDATION config requirement): each
 * operator sets its display identity, default language/locale, currency, timezone,
 * theme (brand colors/logo) and platform log level. The views read this at runtime
 * so the same codebase skins/localises per operator with zero code change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_config', function (Blueprint $table) {
            $table->string('operator_code')->primary();
            $table->string('display_name');
            $table->string('default_locale', 8)->default('en');
            $table->string('currency_code', 3)->default('KES');
            $table->string('timezone')->default('Africa/Nairobi');
            $table->string('date_format')->default('Y-m-d');
            $table->string('theme_primary_color', 16)->default('#4f46e5');
            $table->string('theme_logo_url')->nullable();
            $table->string('log_level')->default('info');       // debug|info|warning|error
            $table->json('extras')->nullable();                  // future per-operator settings
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_config');
    }
};
