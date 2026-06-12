<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ILM-CFG-01 R-ILM-K-3 — the KYC approval authority per level is operator config:
 * which role may approve at each level (KE L1 → an L1 supervisor role, Final → a team
 * leader role). An operator registers its own level→role mapping by editing this table;
 * the service enforces that the approving user holds the configured role. No config row
 * for an (operator, level) means that operator hasn't gated that level yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_approval_role', function (Blueprint $table) {
            $table->string('operator_code');
            $table->unsignedSmallInteger('approval_level');
            $table->string('level_name');
            $table->string('required_role'); // FOUNDATION_AUTH role authorized at this level
            $table->timestamps();
            $table->primary(['operator_code', 'approval_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_approval_role');
    }
};
