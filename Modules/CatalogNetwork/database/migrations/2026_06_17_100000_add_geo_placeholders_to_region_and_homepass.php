<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geo placeholders for serviceability geography (ILM-CFG-02 tech_region) and
 * premises (RLM-CFG-01 homepass), so externally-produced GIS / CGIS data can be
 * IMPORTED into the BSS and rendered on a map. Deliberately storage-only and
 * DB-agnostic: GeoJSON geometry (EPSG:4326) in a json column + a centroid/point
 * for quick pinning, plus import provenance. No spatial engine is assumed here;
 * a PostGIS geometry column + GIST index can layer on later for point-in-polygon
 * queries without changing these placeholders.
 */
return new class extends Migration
{
    public function up(): void
    {
        // tech_region = an AREA → an importable boundary polygon + centroid.
        Schema::table('tech_region', function (Blueprint $table) {
            $table->json('geo_boundary')->nullable();                 // GeoJSON Polygon/MultiPolygon (4326)
            $table->decimal('geo_centroid_lat', 10, 7)->nullable();   // for label/pin/map-centering
            $table->decimal('geo_centroid_lng', 10, 7)->nullable();
            $table->string('geo_source')->nullable();                 // provenance of the imported dataset
            $table->timestamp('geo_imported_at')->nullable();
        });

        // homepass = a POINT premise → coordinates (+ optional footprint polygon).
        Schema::table('homepass', function (Blueprint $table) {
            $table->decimal('geo_lat', 10, 7)->nullable();
            $table->decimal('geo_lng', 10, 7)->nullable();
            $table->json('geo_footprint')->nullable();                // optional GeoJSON building/parcel footprint
            $table->string('geo_source')->nullable();
            $table->timestamp('geo_imported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tech_region', function (Blueprint $table) {
            $table->dropColumn(['geo_boundary', 'geo_centroid_lat', 'geo_centroid_lng', 'geo_source', 'geo_imported_at']);
        });
        Schema::table('homepass', function (Blueprint $table) {
            $table->dropColumn(['geo_lat', 'geo_lng', 'geo_footprint', 'geo_source', 'geo_imported_at']);
        });
    }
};
