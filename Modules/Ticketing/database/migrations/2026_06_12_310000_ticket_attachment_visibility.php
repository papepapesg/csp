<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TCK-01 §7.4 ticket_attachment visibility (INTERNAL vs CUSTOMER_VISIBLE) — needed for
 * §14 self-care "upload approved attachments" and the §13 customer-visible/internal
 * distinction. The binary + storage path stay in FOUNDATION_FILE_STORAGE (file_object);
 * TCK only references file_id (TCK-9: object keys carry no PII).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_attachment', function (Blueprint $table) {
            $table->string('visibility')->default('INTERNAL')->after('size_bytes'); // INTERNAL | CUSTOMER_VISIBLE
        });
    }

    public function down(): void
    {
        Schema::table('ticket_attachment', fn (Blueprint $t) => $t->dropColumn('visibility'));
    }
};
