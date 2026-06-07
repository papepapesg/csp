<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLM-CFG-04 Discount Catalog + SIP-03 Discount Assignment + SIP-05 Campaigns.
 * discount_catalog defines reusable discounts (percent/fixed, scope, stackability,
 * validity); discount_assignment binds a discount to a target (customer /
 * subscription / package / campaign); promo_campaign groups discounts for a
 * segment + window. DIS-OP-01 runtime applies assigned discounts to a charge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_catalog', function (Blueprint $table) {
            $table->string('discount_id')->primary();           // disc_...
            $table->string('operator_code')->index();
            $table->string('code');
            $table->string('name');
            $table->string('discount_type');                    // PERCENT | FIXED
            $table->decimal('value', 12, 4);                    // 0.10 (percent) or 500.00 (fixed)
            $table->string('applies_to')->default('INVOICE');   // INVOICE | PACKAGE | SERVICE
            $table->boolean('stackable')->default(false);
            $table->unsignedInteger('priority')->default(100);  // lower applies first
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->string('status')->default('ACTIVE');        // ACTIVE | INACTIVE
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->timestamps();

            $table->unique(['operator_code', 'code']);
        });

        Schema::create('discount_assignment', function (Blueprint $table) {
            $table->string('assignment_id')->primary();         // dasg_...
            $table->string('operator_code')->index();
            $table->string('discount_code')->index();
            $table->string('scope');                            // CUSTOMER | SUBSCRIPTION | PACKAGE | CAMPAIGN | ALL
            $table->string('scope_ref')->nullable()->index();
            $table->string('campaign_code')->nullable()->index();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('redemptions')->default(0);
            $table->timestamps();
        });

        Schema::create('promo_campaign', function (Blueprint $table) {
            $table->string('campaign_id')->primary();           // camp_...
            $table->string('operator_code')->index();
            $table->string('code');
            $table->string('name');
            $table->string('segment')->nullable();              // target segment code
            $table->string('status')->default('DRAFT');         // DRAFT | ACTIVE | EXPIRED
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->unique(['operator_code', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_campaign');
        Schema::dropIfExists('discount_assignment');
        Schema::dropIfExists('discount_catalog');
    }
};
