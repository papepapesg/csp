<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FUL-02-FRAMEWORK §1.1: the order stores the id of the process instance that
 * drives its journey (POST /api/orders starts the OrderCapture flow; FUL-02 stores
 * the returned id on the order row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulfillment_order', function (Blueprint $table) {
            $table->string('process_instance_id')->nullable()->index()->after('work_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_order', fn (Blueprint $t) => $t->dropColumn('process_instance_id'));
    }
};
