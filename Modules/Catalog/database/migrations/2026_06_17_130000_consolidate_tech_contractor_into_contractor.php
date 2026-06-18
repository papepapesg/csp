<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidate the duplicate contractor model. The contractor is ONE business entity;
 * EM-02 (Workforce `contractor`) is the registry of record. RLM-CFG-01 coverage stops
 * keeping its own `tech_contractor` copy and references the Workforce contractor by id.
 * Existing tech_contractor rows are migrated into `contractor` (same contractor_id, so
 * the tech_region_contractor coverage mappings stay valid), then the table is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tech_contractor')) {
            // Carry the RLM contractors over to the canonical registry (id preserved).
            DB::statement(<<<'SQL'
                INSERT INTO contractor (contractor_id, operator_code, code, name, type, skills, status, created_at, updated_at)
                SELECT tc.contractor_id, tc.operator_code, tc.code, tc.name, 'EXTERNAL', tc.skills, tc.status, now(), now()
                FROM tech_contractor tc
                WHERE NOT EXISTS (
                    SELECT 1 FROM contractor c WHERE c.operator_code = tc.operator_code AND c.code = tc.code
                )
            SQL);

            Schema::drop('tech_contractor');
        }
    }

    public function down(): void
    {
        Schema::create('tech_contractor', function (Blueprint $table) {
            $table->string('contractor_id')->primary();
            $table->string('operator_code')->index();
            $table->string('code');
            $table->string('name');
            $table->json('skills');
            $table->string('status')->default('ACTIVE');
            $table->boolean('has_been_active')->default(false);
            $table->timestamps();
            $table->unique(['operator_code', 'code']);
        });
    }
};
