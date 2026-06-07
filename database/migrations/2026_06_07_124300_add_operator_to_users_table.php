<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds operator scoping + a stable external id to platform users (EM-CFG-03,
 * FE-APP-00 §4). Authorization roles/permissions are owned by spatie/permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('uid')->nullable()->unique()->after('id');
            $table->string('operator_code')->nullable()->index()->after('email');
            $table->string('status')->default('ACTIVE')->after('operator_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['uid', 'operator_code', 'status']);
        });
    }
};
