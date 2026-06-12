<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EM-CFG-04 hardening: allow_requester is operator config (APR-6 segregation of duties —
 * by default a requester cannot approve their own request), and approval_decision is the
 * immutable per-action audit record every approve/reject writes (APR-7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_definition', function (Blueprint $table) {
            $table->boolean('allow_requester')->default(false)->after('required_approvals'); // APR-6 config
        });
        Schema::table('approval_request', function (Blueprint $table) {
            $table->boolean('allow_requester')->default(false)->after('required_approvals');  // snapshot from policy
        });

        Schema::create('approval_decision', function (Blueprint $table) {
            $table->string('decision_id')->primary();           // appdec_...
            $table->string('request_id')->index();
            $table->string('operator_code')->index();
            $table->string('decision');                         // APPROVE | REJECT | REQUEST_REVISION | CANCEL
            $table->string('actor_user_id')->nullable();
            $table->string('comment')->nullable();
            $table->timestamp('decided_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_decision');
        Schema::table('approval_request', fn (Blueprint $t) => $t->dropColumn('allow_requester'));
        Schema::table('approval_definition', fn (Blueprint $t) => $t->dropColumn('allow_requester'));
    }
};
