<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RLM-CFG-01 §Data model — TechContractor skill routing + the HomePass network_path
 * topology. A HomePass's network_path (ordered node chain with ports) drives two derived
 * reads: services_supported (which families the address can carry) and
 * service_management_endpoints (the {nodeCode,port} each gateway must address). Contractor
 * routing answers "which contractors can do skill X at this HomePass" via the
 * tech_region_contractor join's per-assignment skills (R-RLM-CFG-01-A-1).
 */
return new class extends Migration
{
    public function up(): void
    {
        // §tech_contractor_skill — small operator-extensible skill catalog.
        Schema::create('tech_contractor_skill', function (Blueprint $table) {
            $table->string('operator_code');
            $table->string('code');                             // INSTALLATION | MAINTENANCE | SUPPORT | ...
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('status')->default('ACTIVE');        // DRAFT | ACTIVE | RETIRED
            $table->timestamps();
            $table->primary(['operator_code', 'code']);
        });

        // §tech_contractor — the contractor company/team; carries its skill set.
        Schema::create('tech_contractor', function (Blueprint $table) {
            $table->string('contractor_id')->primary();         // tcon_...
            $table->string('operator_code')->index();
            $table->string('code');
            $table->string('name');
            $table->json('skills');                             // array of tech_contractor_skill codes
            $table->string('status')->default('ACTIVE');
            $table->boolean('has_been_active')->default(false);
            $table->timestamps();
            $table->unique(['operator_code', 'code']);
        });

        // §tech_region_contractor — which contractors serve which region, for which skills (join scope).
        Schema::create('tech_region_contractor', function (Blueprint $table) {
            $table->string('tech_region_id');
            $table->string('tech_contractor_id');
            $table->string('operator_code')->index();
            $table->json('skills');                             // subset of the contractor's skills for THIS region
            $table->timestamps();
            $table->primary(['tech_region_id', 'tech_contractor_id']);
            $table->index('tech_contractor_id');
        });

        // §homepass_tech_region — a HomePass is served by one or more TechRegions (many-to-many).
        Schema::create('homepass_tech_region', function (Blueprint $table) {
            $table->string('homepass_id');
            $table->string('tech_region_ref');
            $table->timestamps();
            $table->primary(['homepass_id', 'tech_region_ref']);
            $table->index('tech_region_ref');
        });

        // §homepass — the network topology + derived service reads.
        Schema::table('homepass', function (Blueprint $table) {
            $table->json('network_path')->nullable()->after('network_nodes');           // {captureMode, nodes:[{type,code,role,port}]}
            $table->json('service_management_endpoints')->nullable()->after('network_path'); // {family:{nodeCode,port}}
            $table->json('services_supported')->nullable()->after('service_management_endpoints'); // [DATA,VOICE,IPTV_MULTICAST]
        });
    }

    public function down(): void
    {
        Schema::table('homepass', fn (Blueprint $t) => $t->dropColumn(['network_path', 'service_management_endpoints', 'services_supported']));
        Schema::dropIfExists('homepass_tech_region');
        Schema::dropIfExists('tech_region_contractor');
        Schema::dropIfExists('tech_contractor');
        Schema::dropIfExists('tech_contractor_skill');
    }
};
