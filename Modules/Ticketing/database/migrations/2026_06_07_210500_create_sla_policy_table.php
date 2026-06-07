<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TCK SLA policy catalog (data, not hardcoded). Resolve response hours by
 * (operator, category, priority); operators tune SLAs at runtime with no code
 * change. Most-specific match wins (category+priority > priority > default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_policy', function (Blueprint $table) {
            $table->id();
            $table->string('operator_code')->nullable()->index();
            $table->string('category')->nullable();            // null = any category
            $table->string('priority');                        // LOW|NORMAL|HIGH|URGENT
            $table->unsignedInteger('response_hours');
            $table->timestamps();

            $table->unique(['operator_code', 'category', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_policy');
    }
};
