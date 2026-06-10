<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PROV-INT-01 §7.2: async command execution mode + accept timestamp. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provisioning_command', function (Blueprint $table) {
            $table->string('execution_mode')->default('SYNC')->after('status'); // SYNC | ASYNC_ACCEPTED
            $table->timestamp('accepted_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('provisioning_command', function (Blueprint $table) {
            $table->dropColumn(['execution_mode', 'accepted_at']);
        });
    }
};
