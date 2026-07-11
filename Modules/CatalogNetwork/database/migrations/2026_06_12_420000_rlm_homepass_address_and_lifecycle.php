<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RLM-CFG-01 §homepass — the structured address hierarchy (replacing the flat address line),
 * the building/property/RoE/GIS fields, and the deployment-wide address uniqueness key
 * (R-RLM-CFG-01-H-1). Plus DRAFT/ACTIVE/RETIRED lifecycle columns on franchise and tech_region.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('homepass', function (Blueprint $table) {
            // Structured address hierarchy (H-1 uniqueness tuple).
            $table->string('country')->nullable()->after('address');
            $table->string('region')->nullable();
            $table->string('region_l1')->nullable();
            $table->string('region_l2')->nullable();
            $table->string('city')->nullable();
            $table->string('area')->nullable();
            $table->string('sub_area_1')->nullable();
            $table->string('sub_area_2')->nullable();
            $table->string('road_name')->nullable();
            $table->string('building_number')->nullable();
            $table->string('building_name')->nullable();
            $table->string('apartment_number')->nullable();
            // Building / property.
            $table->string('floor')->nullable();
            $table->unsignedInteger('building_num_floors')->nullable();
            $table->unsignedInteger('building_num_apartments')->nullable();
            $table->string('property_type')->default('RES');     // RES|COM|MIXED|OTHER|TST
            $table->boolean('owner_occupied')->default(false);
            $table->unsignedInteger('outlets')->default(0);
            $table->unsignedInteger('active_termination_points')->default(0);
            // GIS.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('altitude', 8, 2)->nullable();
            $table->string('map_code')->nullable();
            $table->string('map_link')->nullable();
            $table->string('google_place_id')->nullable();       // immutable once set (H-18)
            // RoE / serviceability reason.
            $table->string('not_serviceable_reason')->nullable();
            $table->date('perm_date')->nullable();
            $table->date('survey_date')->nullable();
            $table->date('roe_signed_date')->nullable();
            $table->string('roe_document_link')->nullable();
            // Misc.
            $table->string('legacy_status_code')->nullable();
            $table->text('directions')->nullable();
            $table->text('comments')->nullable();
        });

        // R-RLM-CFG-01-H-1: deployment-wide address uniqueness on the full structured tuple.
        DB::statement("CREATE UNIQUE INDEX idx_homepass_address ON homepass (
            operator_code, country, COALESCE(region,''), COALESCE(region_l1,''), COALESCE(region_l2,''),
            COALESCE(city,''), COALESCE(area,''), COALESCE(sub_area_1,''), COALESCE(sub_area_2,''),
            road_name, COALESCE(building_number,''), COALESCE(building_name,''), COALESCE(apartment_number,'')
        ) WHERE country IS NOT NULL AND road_name IS NOT NULL");

        Schema::table('franchise', function (Blueprint $table) {
            $table->json('boundary_geojson')->nullable();
            $table->boolean('has_been_active')->default(false);
        });
        Schema::table('tech_region', function (Blueprint $table) {
            $table->string('status')->default('ACTIVE');         // DRAFT | ACTIVE | RETIRED
            $table->json('boundary_geojson')->nullable();
            $table->boolean('has_been_active')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('tech_region', fn (Blueprint $t) => $t->dropColumn(['status', 'boundary_geojson', 'has_been_active']));
        Schema::table('franchise', fn (Blueprint $t) => $t->dropColumn(['boundary_geojson', 'has_been_active']));
        DB::statement('DROP INDEX IF EXISTS idx_homepass_address');
        Schema::table('homepass', function (Blueprint $table) {
            $table->dropColumn(['country', 'region', 'region_l1', 'region_l2', 'city', 'area', 'sub_area_1', 'sub_area_2',
                'road_name', 'building_number', 'building_name', 'apartment_number', 'floor', 'building_num_floors',
                'building_num_apartments', 'property_type', 'owner_occupied', 'outlets', 'active_termination_points',
                'latitude', 'longitude', 'altitude', 'map_code', 'map_link', 'google_place_id', 'not_serviceable_reason',
                'perm_date', 'survey_date', 'roe_signed_date', 'roe_document_link', 'legacy_status_code', 'directions', 'comments']);
        });
    }
};
