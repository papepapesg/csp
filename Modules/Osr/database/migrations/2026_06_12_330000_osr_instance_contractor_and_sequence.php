<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OSR-INSTANCE-01 hardening before the tour:
 *  - R-OSR-INST-7: the lifecycle ledger must record which contractor handled a transition
 *    (contractor_id) — THE field downstream routing reads to send a recovered unit back to
 *    the recovering contractor's warehouse (the documented ~40-stale-equipment fix) — plus
 *    a reason_code so "CONTRACTOR_RECOVERED_FROM_FIELD" events are queryable.
 *  - R-OSR-INST-5: event_sequence is monotonic per instance (audit ordering).
 *  - R-OSR-INST-9: DECOMMISSIONED/RETIRED is terminal; the instance row is kept with active=false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_instance_lifecycle_event', function (Blueprint $table) {
            $table->unsignedBigInteger('event_sequence')->default(0)->after('instance_id'); // monotonic per instance
            $table->string('contractor_id')->nullable()->after('reference');                // INST-7 routing fix
            $table->string('reason_code')->nullable()->after('contractor_id');
        });

        Schema::table('equipment_instance', function (Blueprint $table) {
            $table->boolean('active')->default(true)->after('subscription_id'); // false once DECOMMISSIONED/RETIRED
        });
    }

    public function down(): void
    {
        Schema::table('equipment_instance', fn (Blueprint $t) => $t->dropColumn('active'));
        Schema::table('equipment_instance_lifecycle_event', fn (Blueprint $t) => $t->dropColumn(['event_sequence', 'contractor_id', 'reason_code']));
    }
};
