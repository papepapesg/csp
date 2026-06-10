<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ILM-CFG-01: append-only account status/sub-status change history (the audit
 * trail behind the Customer 360 status timeline).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_status_history', function (Blueprint $table) {
            $table->id();
            $table->string('account_id')->index();
            $table->string('operator_code')->index();
            $table->string('prev_status')->nullable();
            $table->string('new_status')->nullable();
            $table->string('prev_sub_status')->nullable();
            $table->string('new_sub_status')->nullable();
            $table->string('reason')->nullable();
            $table->string('changed_by')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_status_history');
    }
};
