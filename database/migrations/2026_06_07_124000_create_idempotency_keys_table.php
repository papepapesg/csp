<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency-Key store for command APIs (DD_API-00 §7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('operator_code')->nullable()->index();
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
