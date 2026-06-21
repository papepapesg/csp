<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ICN-01 direct-address delivery. A delivery normally resolves its address from the recipient's
 * staff channel-identity (or the FOUNDATION_AUTH profile). For a DIRECT send — a message to an
 * explicit address the caller supplies (an email, a WhatsApp/MSISDN, …), e.g. notifying a named
 * approver who isn't a group member — the address rides on the delivery row itself. When present,
 * the dispatcher hands this identity straight to the channel adapter (no directory lookup).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_notification_delivery', function (Blueprint $table) {
            $table->json('recipient_identity')->nullable()->after('recipient_user_id'); // {address|msisdn|...} for a DIRECT send
        });
    }

    public function down(): void
    {
        Schema::table('staff_notification_delivery', fn (Blueprint $t) => $t->dropColumn('recipient_identity'));
    }
};
