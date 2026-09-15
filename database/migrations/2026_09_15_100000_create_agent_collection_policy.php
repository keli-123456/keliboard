<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_agent_collection_policy', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(true);
            $table->boolean('use_global')->default(false);
            $table->boolean('platform_enabled')->default(false);
            $table->text('payment_ids')->nullable();
            $table->unsignedInteger('fee_bps')->default(0);
            $table->unsignedInteger('fee_fixed')->default(0);
            $table->unsignedInteger('settlement_days')->default(7);
            $table->unsignedInteger('minimum_withdrawal')->default(1000);
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedInteger('updated_at');
        });
        // Preserve existing individual authorizations; do not enroll agents automatically.
        DB::table('v2_agent_collection_policy')->insert(['id' => 1, 'payment_ids' => '[]', 'updated_at' => time()]);
    }

    public function down(): void
    {
        throw new RuntimeException('Collection policy cannot be dropped automatically: doing so could reopen collection.');
    }
};
