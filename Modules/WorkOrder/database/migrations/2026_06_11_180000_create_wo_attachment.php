<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WO-01 §1.4 attachments — a categorised file reference on a work order (setup_photo,
 * rf_optical_reading, speed_test, …). The finalize checklist (§4.4) can require one or
 * more attachments per category before second-confirm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wo_attachment', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('operator_code')->index();
            $table->string('work_order_id')->index();
            $table->string('category');                         // setup_photo | rf_optical_reading | speed_test | ...
            $table->string('description')->nullable();
            $table->string('file_uri');                         // storage reference
            $table->string('uploaded_by')->nullable();
            $table->timestamps();

            $table->foreign('work_order_id')->references('work_order_id')->on('work_order')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wo_attachment');
    }
};
