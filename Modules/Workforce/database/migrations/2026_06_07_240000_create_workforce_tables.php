<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EM-02 Contractor & Staff Registry. Canonical workforce reference data
 * (contractors, teams, technicians/staff) referenced by WO assignment and
 * dispatch. EM must not own work orders or ticket lifecycle (HLD §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractor', function (Blueprint $table) {
            $table->string('contractor_id')->primary();        // con_...
            $table->string('operator_code')->index();
            $table->string('code')->index();
            $table->string('name');
            $table->string('type')->default('EXTERNAL');       // INTERNAL | EXTERNAL
            $table->json('skills')->nullable();
            $table->string('status')->default('ACTIVE');       // ACTIVE | SUSPENDED | RETIRED
            $table->timestamps();
            $table->unique(['operator_code', 'code']);
        });

        Schema::create('contractor_team', function (Blueprint $table) {
            $table->string('team_id')->primary();              // team_...
            $table->string('contractor_id')->index();
            $table->string('operator_code')->index();
            $table->string('code');
            $table->string('name');
            $table->json('skills')->nullable();
            $table->string('status')->default('ACTIVE');
            $table->timestamps();

            $table->foreign('contractor_id')->references('contractor_id')->on('contractor')->cascadeOnDelete();
        });

        Schema::create('staff_member', function (Blueprint $table) {
            $table->string('staff_id')->primary();             // stf_...
            $table->string('operator_code')->index();
            $table->string('contractor_id')->nullable()->index();
            $table->string('team_id')->nullable()->index();
            $table->string('name');
            $table->string('role')->default('TECHNICIAN');     // TECHNICIAN | TEAM_LEAD | SUPERVISOR
            $table->string('msisdn')->nullable();
            $table->json('skills')->nullable();
            $table->string('status')->default('ACTIVE');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_member');
        Schema::dropIfExists('contractor_team');
        Schema::dropIfExists('contractor');
    }
};
