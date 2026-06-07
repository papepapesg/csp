<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog & reference data: PLM service catalog (PLM-CFG-01), SIP package
 * management (SIP-01), tech regions (ILM-CFG-02) and HomePass serviceability
 * (RLM-CFG-01). All operator-scoped reference data — must not execute
 * customer-specific operations (HLD §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        // PLM-CFG-01 — Service class (commercial grouping of services).
        Schema::create('service_class', function (Blueprint $table) {
            $table->string('id')->primary();                   // scls_...
            $table->string('operator_code')->index();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('requires_equipment')->default(false);
            $table->string('service_management_key_default')->nullable();
            $table->string('default_tax_group_ref')->nullable();
            $table->string('default_wallet_ref')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        // PLM-CFG-01 — Service (the sellable/provisionable unit).
        Schema::create('service', function (Blueprint $table) {
            $table->string('id')->primary();                   // svc_...
            $table->string('operator_code')->index();
            $table->string('name');
            $table->string('code');
            $table->string('description')->nullable();
            $table->string('service_class_id')->index();
            $table->string('service_group')->nullable();
            $table->boolean('is_addressable')->default(false);
            $table->string('equipment_requirement_ref')->nullable(); // PLM-CFG-01 / PLM-CFG-06
            $table->string('consumption_model')->default('FLAT'); // FLAT | USAGE | ...
            $table->string('revenue_category')->nullable();
            $table->json('network_profile_shape')->nullable();
            $table->string('provisioner_key')->nullable();
            $table->string('default_wallet_ref')->nullable();
            $table->string('default_tax_group_ref')->nullable();
            $table->string('status')->default('ACTIVE');
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['operator_code', 'code']);
            $table->foreign('service_class_id')->references('id')->on('service_class');
        });

        // SIP-01 — Package (commercial offer; composed of services).
        Schema::create('package', function (Blueprint $table) {
            $table->string('id')->primary();                   // pkg_...
            $table->string('operator_code')->index();
            $table->string('code');
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('DRAFT');        // DRAFT|ACTIVE|INACTIVE|END_OF_LIFE
            $table->unsignedInteger('billing_frequency_days')->default(30);
            $table->string('default_wallet_ref')->nullable();
            $table->string('default_tax_group_ref')->nullable();
            $table->json('target_franchises')->nullable();
            $table->json('target_tech_regions')->nullable();
            $table->string('current_version_id')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['operator_code', 'code']);
        });

        // SIP-01 — Package -> service composition.
        Schema::create('package_service', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('package_id')->index();
            $table->string('service_id')->index();
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();

            $table->foreign('package_id')->references('id')->on('package')->cascadeOnDelete();
            $table->unique(['package_id', 'service_id']);
        });

        // SIP-01 — Package version (priced, time-bounded snapshot).
        Schema::create('package_version', function (Blueprint $table) {
            $table->string('id')->primary();                   // pkv_...
            $table->string('package_id')->index();
            $table->decimal('price', 12, 2);
            $table->string('currency', 3);
            $table->json('target_franchises')->nullable();
            $table->json('target_tech_regions')->nullable();
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->string('status')->default('PENDING');      // PENDING|ACTIVE|SUPERSEDED
            $table->timestamps();

            $table->foreign('package_id')->references('id')->on('package')->cascadeOnDelete();
        });

        // ILM-CFG-02 — Tech region registry (serviceability geography).
        Schema::create('tech_region', function (Blueprint $table) {
            $table->string('tech_region_id')->primary();       // KE-NRB-KAREN
            $table->string('operator_code')->index();
            $table->string('parent_region_id')->nullable();
            $table->string('display_name_primary');
            $table->string('display_name_secondary')->nullable();
            $table->string('region_type');                     // COUNTRY|PROVINCE|CITY|NEIGHBORHOOD|CUSTOM
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();
        });

        // RLM-CFG-01 — HomePass (a serviceable physical address/premise).
        Schema::create('homepass', function (Blueprint $table) {
            $table->string('id')->primary();                   // hp_...
            $table->string('operator_code')->index();
            $table->string('code')->nullable();
            $table->string('address');
            $table->string('tech_region_id')->nullable()->index();
            $table->string('technology')->nullable();          // GPON | HFC | ...
            $table->string('status')->default('DRAFT');        // DRAFT|SERVICEABLE|RESERVED|RETIRED
            $table->boolean('has_been_active')->default(false);
            $table->json('network_nodes')->nullable();
            $table->timestamps();

            $table->unique(['operator_code', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homepass');
        Schema::dropIfExists('tech_region');
        Schema::dropIfExists('package_version');
        Schema::dropIfExists('package_service');
        Schema::dropIfExists('package');
        Schema::dropIfExists('service');
        Schema::dropIfExists('service_class');
    }
};
