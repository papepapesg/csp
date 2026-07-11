<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RLM-CFG-01 reference catalogs the HomePass module owns: house_type (building-density
 * classification) and network_node (the controlled list of OLTs/FATs/splitters a HomePass's
 * network_path references, with a walkable parent chain). Plus the homepass↔franchise join
 * (franchise table is shared with EM-01) and the homepass house_type_code FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('house_type', function (Blueprint $table) {
            $table->string('operator_code');
            $table->string('code');                             // M2M | M1H | S1H | ...
            $table->string('description');
            $table->unsignedInteger('display_order')->default(0);
            $table->string('status')->default('ACTIVE');        // DRAFT | ACTIVE | RETIRED
            $table->timestamps();
            $table->primary(['operator_code', 'code']);
        });

        Schema::create('network_node', function (Blueprint $table) {
            $table->string('node_id')->primary();               // nnode_...
            $table->string('operator_code')->index();
            $table->string('code');                             // RNA, TK7, OLT-NRB-WTL-01, ...
            $table->string('type');                             // OLT|SPLITTER|FAT|FDT|ONT|HEADEND|DISTRIBUTION_NODE|AMPLIFIER|LINE_EXTENDER|VOIPSWITCH|NMS|OTHER
            $table->string('name');
            $table->string('parent_node_code')->nullable();     // walkable chain (no cycles)
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status')->default('ACTIVE');
            $table->timestamps();
            $table->unique(['operator_code', 'code']);
            $table->index(['operator_code', 'type']);
        });

        Schema::create('homepass_franchise', function (Blueprint $table) {
            $table->string('homepass_id');
            $table->string('franchise_ref');                    // franchise.franchise_id (EM-01)
            $table->timestamps();
            $table->primary(['homepass_id', 'franchise_ref']);
            $table->index('franchise_ref');
        });

        Schema::table('homepass', function (Blueprint $table) {
            $table->string('house_type_code')->nullable()->after('technology'); // FK to house_type (catalog)
        });
    }

    public function down(): void
    {
        Schema::table('homepass', fn (Blueprint $t) => $t->dropColumn('house_type_code'));
        Schema::dropIfExists('homepass_franchise');
        Schema::dropIfExists('network_node');
        Schema::dropIfExists('house_type');
    }
};
