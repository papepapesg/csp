<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OSR-02 §5: a purchase order's approval is owned by EM-CFG-04. OSR-02 stores only the
 * approval reference + final outcome (the PO can now sit in PENDING_APPROVAL while the
 * EM-CFG-04 request is open). Previously approve() flipped DRAFT->APPROVED with no gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order', function (Blueprint $table) {
            $table->string('approval_request_id')->nullable()->after('status'); // EM-CFG-04 request
            $table->string('approval_mode')->nullable()->after('approval_request_id'); // EM-CFG-04 mode snapshot
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order', fn (Blueprint $table) => $table->dropColumn(['approval_request_id', 'approval_mode']));
    }
};
