<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FE-APP-04 customer self-care: a self-care login (role CUSTOMER) is associated
 * with the ILM customer whose account it manages, so self-care endpoints scope all
 * reads/writes to that customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('customer_id')->nullable()->index()->after('operator_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('customer_id');
        });
    }
};
