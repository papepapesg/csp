<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenancy-scope the idempotency key. The key was globally unique, so two operators using
 * the same Idempotency-Key collided (409) or — worse — one tenant's stored response could be
 * replayed to another. Uniqueness is now (operator_code, key); the middleware looks up by both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropUnique('idempotency_keys_key_unique');
            $table->unique(['operator_code', 'key'], 'idempotency_keys_operator_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropUnique('idempotency_keys_operator_key_unique');
            $table->unique('key', 'idempotency_keys_key_unique');
        });
    }
};
