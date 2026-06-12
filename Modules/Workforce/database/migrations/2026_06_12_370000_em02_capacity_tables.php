<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EM-02 capacity model — the heart of the module and the WO module's hot path. Per-region
 * service-scope coverage, an operator-extensible skill catalog + per-region contractor
 * certification (the WO routing filter), availability slots with concurrency caps, and the
 * immutable slot-commitment ledger from which remaining capacity is derived.
 */
return new class extends Migration
{
    public function up(): void
    {
        // §3.2 per-region service-scope coverage (PRIMARY / BACKUP / EXCLUSIVE), time-versioned.
        Schema::create('contractor_region_scope', function (Blueprint $table) {
            $table->string('coverage_id')->primary();           // cov_...
            $table->string('operator_code')->index();
            $table->string('contractor_id')->index();
            $table->string('tech_region_id');                   // FK to ILM-CFG-02 (not enforced cross-module)
            $table->string('service_scope');                    // INSTALL | SUPPORT | MAINTENANCE | RECOVERY | AUDIT
            $table->string('coverage_role')->default('PRIMARY'); // PRIMARY | BACKUP | EXCLUSIVE
            $table->date('effective_from');
            $table->date('effective_to')->nullable();           // NULL = ongoing
            $table->timestamps();
            $table->index(['tech_region_id', 'service_scope', 'effective_to']);
        });

        // §3.3 operator-extensible skill catalog.
        Schema::create('skill_catalog', function (Blueprint $table) {
            $table->string('operator_code');
            $table->string('skill_code');                       // fiber-install | ftth-cpe | vip-handling | ...
            $table->string('display_name');
            $table->string('category')->nullable();             // TECHNICAL_INSTALL | TECHNICAL_SUPPORT | SOFT_SKILL | ...
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->primary(['operator_code', 'skill_code']);
        });

        // §3.4 which skills a contractor is certified for in each region (the WO routing filter).
        Schema::create('contractor_region_skill', function (Blueprint $table) {
            $table->string('contractor_id');
            $table->string('tech_region_id');
            $table->string('operator_code')->index();
            $table->string('skill_code');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->primary(['contractor_id', 'tech_region_id', 'skill_code']);
            $table->index(['tech_region_id', 'skill_code', 'active']);
        });

        // §3.5 day-of-week + hour-range windows with concurrency caps — the unit capacity is reserved against.
        Schema::create('contractor_availability_slot', function (Blueprint $table) {
            $table->string('slot_id')->primary();               // slot_...
            $table->string('operator_code')->index();
            $table->string('contractor_id')->index();
            $table->string('tech_region_id');
            $table->string('service_scope');
            $table->string('day_of_week');                      // MONDAY..SUNDAY | ALL_WEEK
            $table->time('hour_start');
            $table->time('hour_end');
            $table->string('timezone')->default('Africa/Nairobi');
            $table->unsignedInteger('max_concurrent');          // capacity cap
            $table->boolean('emergency_only')->default(false);
            $table->boolean('active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->index(['tech_region_id', 'service_scope', 'day_of_week', 'active']);
        });

        // §3.6 immutable commitment ledger; capacity = max_concurrent − count(ACTIVE same day).
        Schema::create('contractor_slot_commitment', function (Blueprint $table) {
            $table->string('commitment_id')->primary();         // cmt_...
            $table->string('operator_code')->index();
            $table->string('slot_id')->index();
            $table->string('contractor_id')->index();
            $table->string('wo_id');
            $table->timestamp('committed_for_datetime');
            $table->unsignedInteger('qty')->default(1);
            $table->string('status')->default('ACTIVE');        // ACTIVE | CONSUMED | RELEASED | EXPIRED
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();
            $table->index(['slot_id', 'status']);
            $table->index('wo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_slot_commitment');
        Schema::dropIfExists('contractor_availability_slot');
        Schema::dropIfExists('contractor_region_skill');
        Schema::dropIfExists('skill_catalog');
        Schema::dropIfExists('contractor_region_scope');
    }
};
