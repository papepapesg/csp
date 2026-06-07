<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOT-01 notification service + ICN-01 internal communications. NOT/ICN own
 * notification/communication records; they must not decide business policy (HLD §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification', function (Blueprint $table) {
            $table->string('notification_id')->primary();      // ntf_...
            $table->string('operator_code')->index();
            $table->string('channel');                         // SMS | EMAIL | PUSH | WHATSAPP
            $table->string('recipient');                       // msisdn / email / device token
            $table->string('template_code')->nullable();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->string('status')->default('QUEUED')->index(); // QUEUED|SENT|FAILED|DELIVERED
            $table->string('customer_id')->nullable()->index();
            $table->string('reference')->nullable()->index();  // source entity (invoice/ticket/...)
            $table->string('failure_reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('internal_message', function (Blueprint $table) {
            $table->string('message_id')->primary();           // icn_...
            $table->string('operator_code')->index();
            $table->string('to_group')->nullable()->index();   // candidate group / role
            $table->string('to_user_id')->nullable()->index();
            $table->string('subject');
            $table->text('body')->nullable();
            $table->string('priority')->default('NORMAL');
            $table->string('reference')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_message');
        Schema::dropIfExists('notification');
    }
};
