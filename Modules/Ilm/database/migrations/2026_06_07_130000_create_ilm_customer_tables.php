<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ILM-CFG-01 — Customer master (CAS hierarchy tiers 1 & 2) + KYC, contacts,
 * notes and interaction history. ILM owns customer/account; it must NOT own
 * subscription lifecycle or billing ledgers (HLD §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tier 1 — Customer: the legal entity (person or business).
        Schema::create('customer', function (Blueprint $table) {
            $table->string('customer_id')->primary();          // cust_01HX... ULID
            $table->string('operator_code')->index();          // WIK | WUG | WTZ | YASSN
            $table->string('type');                            // RES | COM
            $table->string('name');
            $table->string('identification_type_1')->nullable();
            $table->string('identification_number_1')->nullable();
            $table->string('identification_type_2')->nullable();
            $table->string('identification_number_2')->nullable();
            $table->date('date_of_birth')->nullable();         // RES
            $table->date('business_reg_date')->nullable();      // COM
            $table->string('primary_msisdn');                  // +254712345678 (required)
            $table->string('email')->nullable();
            $table->string('preferred_language')->default('en');
            $table->string('kyc_status')->default('PENDING');  // derived from approvals
            $table->timestamps();

            $table->index('primary_msisdn');
            $table->index('email');
            $table->index(['identification_type_1', 'identification_number_1']);
            $table->index(['identification_type_2', 'identification_number_2']);
        });

        // Tier 2 — Account: one physical service deployment (1 account = 1 deployment).
        Schema::create('customer_account', function (Blueprint $table) {
            $table->string('account_id')->primary();           // acct_01HX...
            $table->string('account_number');                  // operational key, unique per operator
            $table->string('payment_account_number')->nullable(); // gateway-callback resolution key
            $table->string('customer_id');
            $table->string('operator_code')->index();
            $table->string('homepass_id')->nullable()->index(); // RLM-CFG-01 physical address
            $table->string('service_address');
            $table->string('status')->default('INACTIVE');     // INACTIVE | ACTIVE
            $table->string('sub_status')->default('NEW');      // see sub-status registry
            $table->string('sub_status_reason')->nullable();
            $table->timestamp('sub_status_changed_at')->useCurrent();
            $table->string('service_class_1')->nullable();
            $table->string('service_class_2')->nullable();
            $table->string('service_class_3')->nullable();
            $table->string('attention_banner')->nullable();
            $table->string('subscription_id')->nullable();     // 1:1 link to SUB-LM-01 (no cross-module FK)
            $table->date('start_bill_date')->nullable();
            $table->date('install_date')->nullable();
            $table->date('disconnect_date')->nullable();
            $table->string('account_manager_id')->nullable();
            $table->string('franchise_code')->nullable()->index();
            $table->timestamps();

            $table->foreign('customer_id')->references('customer_id')->on('customer');
            $table->unique(['operator_code', 'account_number']);
            $table->unique(['operator_code', 'payment_account_number']);
            $table->index('customer_id');
            $table->index(['status', 'sub_status']);
            $table->index('subscription_id');
        });

        // Contact methods (channels) attached to a customer.
        Schema::create('customer_contact_method', function (Blueprint $table) {
            $table->string('id')->primary();                   // cm_...
            $table->string('customer_id')->index();
            $table->string('type');                            // MSISDN | EMAIL | LANDLINE | WHATSAPP
            $table->string('value');
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('customer_id')->on('customer');
        });

        // Free-text notes (separate from structured interactions).
        Schema::create('customer_note', function (Blueprint $table) {
            $table->string('id')->primary();                   // note_...
            $table->string('customer_id')->index();
            $table->string('kind')->default('GENERAL');
            $table->text('body');
            $table->string('author_id')->nullable();
            $table->string('superseded_by')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('customer_id')->on('customer');
        });

        // Structured interaction history (CSR action log).
        Schema::create('customer_interaction', function (Blueprint $table) {
            $table->string('id')->primary();                   // int_...
            $table->string('customer_id')->index();
            $table->string('agent_name')->nullable();
            $table->string('reason')->nullable();              // call reason
            $table->text('findings')->nullable();
            $table->string('resolution_ticket')->nullable();   // ticket #
            $table->text('voc')->nullable();                   // voice-of-customer verbatim
            $table->timestamps();

            $table->foreign('customer_id')->references('customer_id')->on('customer');
        });

        // KYC approval chain. customer.kyc_status is derived from these rows.
        Schema::create('customer_kyc_approval', function (Blueprint $table) {
            $table->string('id')->primary();                   // kyc_...
            $table->string('customer_id')->index();
            $table->unsignedTinyInteger('approval_level');     // 1 (L1) | 2 (final)
            $table->string('approval_level_name')->nullable(); // L1_SUPERVISOR | FINAL
            $table->string('decision');                        // APPROVED | REJECTED
            $table->boolean('is_final')->default(false);
            $table->string('approver_id')->nullable();
            $table->string('approver_role')->nullable();
            $table->text('comments')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('customer_id')->on('customer');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_kyc_approval');
        Schema::dropIfExists('customer_interaction');
        Schema::dropIfExists('customer_note');
        Schema::dropIfExists('customer_contact_method');
        Schema::dropIfExists('customer_account');
        Schema::dropIfExists('customer');
    }
};
