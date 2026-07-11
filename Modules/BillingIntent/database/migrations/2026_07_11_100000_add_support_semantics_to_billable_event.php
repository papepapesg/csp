<?php

use App\Foundation\Catalog\SupportLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billable_event', function (Blueprint $table): void {
            $table->string('support_level')->default(SupportLevel::EXECUTABLE->value)->after('status');
            $table->string('behavior_key')->nullable()->after('support_level');
            $table->index(['operator_code', 'status', 'support_level'], 'billable_event_runtime_idx');
        });
    }

    public function down(): void
    {
        Schema::table('billable_event', function (Blueprint $table): void {
            $table->dropIndex('billable_event_runtime_idx');
            $table->dropColumn(['support_level', 'behavior_key']);
        });
    }
};
