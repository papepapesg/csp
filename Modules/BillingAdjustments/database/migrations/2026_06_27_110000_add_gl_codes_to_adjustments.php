<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-02-ADJ-01 finance integration: map adjustments to a GL account. The reason code carries
 * the posting account per direction (a CREDIT and a DEBIT account, since an ANY reason posts to
 * different ledgers depending on direction); the resolved gl_code is stamped on the adjustment
 * and on each note_application_ledger row so every posting is finance-reconcilable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adjustment_reason_code', function (Blueprint $table) {
            $table->string('credit_gl_code')->nullable()->after('direction'); // GL account for CREDIT postings
            $table->string('debit_gl_code')->nullable()->after('credit_gl_code'); // GL account for DEBIT postings
        });
        Schema::table('adjustment_request', function (Blueprint $table) {
            $table->string('gl_code')->nullable()->after('reason_code'); // resolved from reason × direction
        });
        Schema::table('note_application_ledger', function (Blueprint $table) {
            $table->string('gl_code')->nullable()->after('currency'); // the posting account for this application
        });
    }

    public function down(): void
    {
        Schema::table('adjustment_reason_code', function (Blueprint $table) {
            $table->dropColumn(['credit_gl_code', 'debit_gl_code']);
        });
        Schema::table('adjustment_request', function (Blueprint $table) {
            $table->dropColumn('gl_code');
        });
        Schema::table('note_application_ledger', function (Blueprint $table) {
            $table->dropColumn('gl_code');
        });
    }
};
